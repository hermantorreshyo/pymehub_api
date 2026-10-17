# Documentación · pymehub_api

| Carpeta / archivo | Contenido |
|---|---|
| [`PROMPT_MAESTRO.md`](PROMPT_MAESTRO.md) | Prompt maestro para iniciar o retomar el desarrollo con un asistente de IA (igual en ambos repositorios) |
| [`requisitos/`](requisitos/) | Requisitos técnicos del MVP (fuente de verdad) |
| [`requerimientos/`](requerimientos/) | Requerimientos puntuales posteriores, con sus notas de implementación (p. ej. `REQ_QR_DEMO_COMERCIAL.md`, demo comercial y QR del folleto) |
| [`openapi.yaml`](openapi.yaml) | Contrato con el frontend (`DEC-13`) |
| [`coleccion/`](coleccion/) | Colección de Postman de la API y entornos local y producción |
| [`hoja_tecnica_modelo_riesgo.md`](hoja_tecnica_modelo_riesgo.md) | Hoja técnica del motor de riesgo de rotación v1.1: datos, reglas, fuentes, AI Act, revisión de sesgos, validación y limitaciones (`RF-035`, `LEG-006`, `LEG-015`) |
| [`base_de_datos/MODELO_DATOS.md`](base_de_datos/MODELO_DATOS.md) | Modelo interlocutor (`tipo`) → usuario (`perfil`) |
| [`manuales/01_MANUAL_DESPLIEGUE.md`](manuales/01_MANUAL_DESPLIEGUE.md) | Despliegue general: motor de base de datos (MariaDB 11.8 o MySQL 8.4), HTTPS, copias, monitorización y actualizaciones |
| [`manuales/02_DESPLIEGUE_VPS_PRODUCCION.md`](manuales/02_DESPLIEGUE_VPS_PRODUCCION.md) | Despliegue y actualización en el VPS de producción (`srv1616185`) sobre su MariaDB 11.8 compartido, activación del asesor de IA y problemas reales de la instalación |
| [`legal/CONTRATO_ENCARGO_TRATAMIENTO.md`](legal/CONTRATO_ENCARGO_TRATAMIENTO.md) | Plantilla del contrato de encargo del tratamiento (art. 28 RGPD, `LEG-016`, `LEG-021`), pendiente de la constitución de la sociedad y de revisión jurídica. Los textos legales públicos están en `pymehub_web` (`#/legal/…`) |
| [`manuales/03_STRIPE_MODO_PRUEBA.md`](manuales/03_STRIPE_MODO_PRUEBA.md) | Pagos con Stripe en modo prueba: claves con `cli/stripe_setup.php`, webhook, portal del cliente, tarea diaria y prueba de extremo a extremo (`RF-114` a `RF-120`) |
| [`ejemplos/plantilla_empleados.csv`](ejemplos/plantilla_empleados.csv) | Plantilla para la importación CSV de empleados |
| [`anexos/`](anexos/) | Documento final del TFM, anexo del prototipo de interfaz y presupuesto (Anexo A) |

Los manuales de marca, de maquetación y los mockups están en `pymehub_web/docs/diseno/`.
