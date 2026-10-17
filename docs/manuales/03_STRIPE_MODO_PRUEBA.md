# Manual 03 · Pagos con Stripe en modo prueba

**Versión:** API 0.9.0 · 17/10/2026 · Requisitos `RF-114` a `RF-120`.

Pyme Hub cobra la suscripción con Stripe **solo en modo prueba**: no se mueve dinero real. Si en `config.php` hay una clave `sk_live_`, el pago online se desactiva solo (`PH-PAY-001`). Cobrar de verdad exige una sociedad constituida y una revisión fiscal y jurídica, y queda fuera del MVP.

Las claves **nunca** se pegan en un chat, un correo ni el repositorio. Se escriben en el servidor con `cli/stripe_setup.php`, que no las muestra en pantalla.

---

## 1. Cómo funciona

| Paso | Quién | Qué pasa |
|---|---|---|
| 1 | Gerente | En **Suscripción** elige plan y ciclo y ve el resumen: base, IVA (21 %) y total. Acepta los términos y pulsa «Pagar con Stripe». |
| 2 | API | Crea el cliente de Stripe (una vez) y la sesión de Checkout: precio por empleado activo **sin IVA** más un *tax rate* exclusivo del IVA parametrizado. Si la empresa está en prueba, el primer cobro se hace al terminar la prueba. |
| 3 | Stripe | Cobra con la tarjeta de prueba y avisa a Pyme Hub por webhook. |
| 4 | API | Con `invoice.paid` registra el pago (base, IVA, total y enlace a la factura) y pone la suscripción **VIGENTE** hasta el último día pagado. La empresa pasa de piloto a activa. |
| 5 | Cada noche | `cli/subscription_status.php` guarda la vigencia (prueba, vigente, gracia, vencida) y ajusta en Stripe el número de empleados activos. |

Sin pago, al pasar `periodo_fin` la empresa entra en **gracia** (7 días por defecto, en Parámetros) y luego queda **vencida**: solo lectura hasta renovar. La plataforma también puede renovar a mano: Empresas → **Vigencia**, con el pago por transferencia registrado con su IVA.

---

## 2. Configuración en el panel de Stripe (una vez)

Entra en [dashboard.stripe.com](https://dashboard.stripe.com) y activa **«Modo de prueba»** (*Test mode*) arriba a la derecha. Todo lo que sigue se hace en modo prueba.

### 2.1 Claves de la API

Ve a **Desarrolladores → Claves de API** y ten a mano:
- la clave publicable `pk_test_…`;
- la clave secreta `sk_test_…` («Mostrar clave de prueba»).

### 2.2 Escribir las claves en el servidor

```bash
API=/var/www/hermantorres/eude/pymehub/pymehub_api
sudo -u www-data php $API/cli/stripe_setup.php
#   Clave secreta (sk_test_…):            ← pegar; no se ve al escribir
#   Clave publicable (pk_test_…):         ← pegar
#   Secreto del webhook (whsec_…, …):     ← Intro; se añade en el paso 2.3
#   Pago online: ACTIVO (modo prueba)
```

El script guarda una copia del `config.php` anterior (`config.php.bak-…`, permisos 600). Bórrala cuando hayas comprobado que todo funciona.

### 2.3 Webhook

En **Desarrolladores → Webhooks → Añadir destino** (*Add endpoint*):

| Ajuste | Valor |
|---|---|
| URL | `https://api.pymehub.hermantorres.com/v1/stripe/webhook` |
| Versión de la API | La que proponga Stripe (la API lee los campos de varias versiones) |
| Eventos | `checkout.session.completed`, `invoice.paid`, `invoice.payment_failed`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted` |

Guarda el destino, copia el **secreto de firma** (`whsec_…`) y vuelve a ejecutar el script. Pulsa Intro en las dos claves para conservarlas y pega solo el secreto:

```bash
sudo -u www-data php $API/cli/stripe_setup.php
```

### 2.4 Portal del cliente

En **Configuración → Facturación → Portal de clientes** (modo prueba), activa:
- método de pago (actualizar la tarjeta);
- historial de facturas;
- cancelar la suscripción **al final del periodo de facturación**.

Deja **desactivado** «cambiar de plan»: los cambios de plan los gestiona la plataforma. Guarda los cambios; sin guardar, Stripe no abre el portal.

### 2.5 Tarea diaria

```bash
grep -q subscription_status /etc/cron.d/pymehub || sed -i '/backup.sh/i 15 0 * * *  www-data  php $API/cli/subscription_status.php >> $API/storage/logs/cron.log 2>&1' /etc/cron.d/pymehub
cat /etc/cron.d/pymehub
sudo -u www-data php $API/cli/subscription_status.php    # "Vigencias actualizadas: N"
```

---

## 3. Prueba de extremo a extremo

1. **Crea una empresa de prueba** en Administración → Empresas, con un email tuyo como gerente. Empieza en prueba gratuita de 30 días. Activa la invitación y entra como gerente.
2. **Abre Suscripción** y elige un plan y un ciclo. El resumen muestra la base, el IVA y el total. Acepta los términos y pulsa **Pagar con Stripe**.
3. **En Stripe**, usa la tarjeta `4242 4242 4242 4242`, cualquier fecha futura, cualquier CVC y cualquier código postal. Al estar en prueba gratuita, Stripe muestra «0 € hoy» y el primer cobro al terminar la prueba.
4. **Vuelve a Pyme Hub.** La pantalla dice «Confirmando el pago…» y en unos segundos «Pago online activado». El estado sigue en *Prueba* hasta su fin.
5. **Para ver un cobro inmediato**:
   - en Administración → Empresas → **Vigencia**, fija una fecha a 1 o 2 días vista (**Fijar fecha**, tipo *Prueba*) en otra empresa de prueba;
   - repite el pago: Stripe cobra al momento porque quedan menos de 48 h de prueba;
   - aparece la factura en «Facturas y pagos» y en **Administración → Pagos**, con «Ver» enlazado a la factura de Stripe.
6. **Portal:** «Gestionar el pago y las facturas» abre el portal. Cancela al final del periodo y comprueba en Suscripción «se cancela al final del periodo».
7. **En Stripe → Desarrolladores → Webhooks**, todos los eventos deben aparecer con respuesta **200**.

| Tarjeta de prueba | Resultado |
|---|---|
| `4242 4242 4242 4242` | Pago correcto |
| `4000 0025 0000 3155` | Pide autenticación 3D Secure |
| `4000 0000 0000 0341` | Se guarda, pero el cobro falla: genera `invoice.payment_failed` |

---

## 4. Problemas frecuentes

| Síntoma | Causa | Solución |
|---|---|---|
| «El pago online no está disponible» (`PH-PAY-001`) | `stripe.enabled` en falso, clave vacía o que no es `sk_test_` | `cli/stripe_setup.php` |
| «No hemos podido conectar con la pasarela» (`PH-PAY-002`) | Clave no válida, portal sin guardar (paso 2.4) o el servidor no llega a `api.stripe.com` | `grep '"Stripe respondió' $API/storage/logs/api-*.log \| tail -3` muestra el código de Stripe, sin datos sensibles |
| Webhooks con **400** en Stripe | El `whsec_` no corresponde a ese destino | Copia de nuevo el secreto del destino y ejecuta el script |
| Webhooks con **5xx** | Error al procesar el evento; Stripe lo reintenta solo | Busca el `cid` en `storage/logs/api-*.log` |
| Tras pagar, sigue «Confirmando el pago…» | El webhook no llega | Revisa la URL del destino y los eventos marcados (2.3) |
| La empresa no puede escribir (`PH-PLAN-002`) | Suscripción vencida tras la gracia | Pagar en Suscripción, o Administración → Empresas → Vigencia |

---

## 5. Lo que queda fuera del MVP

- Cobros reales (modo live).
- Facturación con los datos fiscales del titular: hasta que la sociedad esté constituida, las facturas son las de prueba de Stripe.
- Cambios de plan desde el portal.
- Reembolsos y disputas, que se gestionan en el panel de Stripe.
- Recordatorios por correo.
