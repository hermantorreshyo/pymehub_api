# Hoja técnica del modelo de riesgo de rotación · reglas v1.1

**Pyme Hub · IA de RR. HH.** · Documento exigido por `RF-035` y `LEG-006` · Versión de reglas: **v1.1** · Última revisión: 12/10/2026

| Campo | Valor |
|---|---|
| Tipo de sistema | Motor de **reglas ponderadas** (no aprendizaje automático, `DEC-07`) |
| Código | `src/Services/RiesgoService.php`, pesos en `config/risk_rules.php` |
| Ejecución | Diaria a las 03:00 (`cli/recalc_risk.php`) y bajo demanda (`POST /v1/risk/recalculate`, una vez cada 10 minutos por empresa) |
| Disponible en | Plan **Completo** (`riesgo_rotacion`); el plan Entrada recibe `PH-PLAN-001` |
| Quién lo ve | Gerencia y RR. HH. de la propia empresa. **Nunca** el empleado ni otras empresas |
| Responsable | Equipo de Pyme Hub (TFM EUDE) |

---

## 1. Propósito

Avisar a tiempo a la gerencia y a RR. HH. de una PYME de logística de última milla de que una persona reúne señales que la literatura asocia a la baja voluntaria, **para que tengan una conversación con ella**. El modelo:

- **No decide nada.** No bloquea, no puntúa el desempeño y no propone sanciones ni despidos. El sistema solo alerta y cualquier acción la toma una persona (`LEG-005`).
- **No predice una probabilidad.** La puntuación de 0 a 100 ordena a las personas según cuántas señales reúnen, con un peso que refleja la fuerza de la evidencia de cada señal.

Texto que acompaña siempre a la puntuación: *«Esta puntuación orienta una conversación, no decide nada sobre la persona.»*

## 2. Datos que usa

Solo datos que la gerencia ya maneja legítimamente para gestionar la relación laboral (§9.2):

| Dato | Tabla | Factor |
|---|---|---|
| Fecha de alta | `ficha_laboral.fecha_alta` | F1 |
| Ausencias injustificadas de los últimos 60 días | `incidencia` (`AUSENCIA_INJUSTIFICADA`) | F2 |
| Tipo de contrato | `ficha_laboral.tipo_contrato` | F3 |
| Formación **asignada por la empresa**, vencida y sin completar | `asignacion_formativa` (`origen = GESTION`) | F4 |
| Turno | `ficha_laboral.turno` | F5 |
| Siniestros y quejas de cliente de los últimos 60 días | `incidencia` | F6 |
| Reconocimientos de los últimos 90 días | `incidencia` (`RECONOCIMIENTO`) | F7 |

**Nunca entran en el cálculo:**

- datos de salud ni bajas médicas, que ni siquiera se registran (`LEG-012`);
- respuestas individuales del check-in de bienestar ni la participación en él (`LEG-013`);
- preferencias del asesor de formación;
- autoinscripciones voluntarias en formación (`RF-047`);
- edad, sexo, nacionalidad ni ningún otro dato personal.

## 3. Reglas v1.1

| # | Factor | Condición | Puntos | Evidencia | Fuentes |
|---|---|---|---:|---|---|
| F1 | Antigüedad | Menos de 90 días / de 90 a 365 días | 30 / 15 | Fuerte | S1, S2 |
| F2 | Ausencias injustificadas | 2 o más en los últimos 60 días / 1 | 25 / 10 | Fuerte (meta-analítica) | S3 |
| F3 | Tipo de contrato | Temporal o ETT | 15 | Moderada | S4 |
| F4 | Formación vencida | 1 o más asignaciones de la empresa vencidas sin completar | 10 | Débil (declarativa) | S5 |
| F5 | Turno | Noche o rotativo | 10 | Moderada, indirecta (otro sector) | S6 |
| F6 | Siniestros o quejas de cliente | 1 o más en los últimos 60 días | 10 | Hipótesis del equipo, sin fuente directa | Validar en piloto |
| F7 | Reconocimiento | 1 o más en los últimos 90 días | **−15** | Moderada-fuerte (longitudinal) | S7 |
| — | Retrasos | — | 0 (se registran, no puntúan) | Evidencia contraria | S3 |
| — | Participación en el check-in | — | Excluida | Decisión ética y legal | `LEG-013` |

**Total.** Se suman los factores que se cumplen y se acota el resultado a 0–100. El máximo teórico es 100 (30 + 25 + 15 + 10 + 10 + 10) antes de aplicar F7.

**Niveles** (`RF-033`):

| Nivel | Puntos | Consecuencia |
|---|---|---|
| **Bajo** | 0 a 29 | Ninguna |
| **Medio** | 30 a 54 | Aparece en el ranking; conviene seguirlo |
| **Alto** | 55 a 100 | Crea una **alerta de rotación** para RR. HH., visible también para la gerencia. La alerta solo se crea al **pasar** a nivel alto y si no hay otra abierta; prioridad alta desde 70 |

Casos de control:

| Caso | Cálculo | Nivel |
|---|---|---|
| Recién llegado | 30 | Medio |
| Recién llegado, temporal y en turno de noche | 30 + 15 + 10 = 55 | Alto |
| Recién llegado, temporal y con 2 ausencias | 30 + 15 + 25 = 70 | Alto, prioridad alta |
| Veterano con retrasos | 0 | Bajo (los retrasos no puntúan) |

## 4. Explicabilidad y trazabilidad

- **Una puntuación por persona y día** (`puntuacion_riesgo`, clave única `usuario_id + fecha`, migración `0006`). Cada fila guarda los factores con sus puntos, una frase en lenguaje llano, la evidencia, las fuentes y la versión de reglas (`RF-032`). Así cualquier puntuación se puede explicar y reproducir.
- **La ficha de la persona** muestra siempre la puntuación con los factores en lenguaje llano y el enlace «¿De dónde sale esto?» a la metodología (`RF-034`, `GET /v1/risk/methodology`).
- **Cada consulta a la ficha de riesgo** queda en `registro_auditoria` con la acción `riesgo.ficha_consultada` (`LEG-004`). También se auditan `riesgo.recalculado` y `linea_base.registrada`.
- **Cambiar un peso, una condición o una fuente exige subir la versión** en `config/risk_rules.php`. Las puntuaciones anteriores conservan la versión con la que se calcularon.

## 5. Encaje en el AI Act

El motor se basa en reglas definidas y documentadas por el equipo, sin inferencia a partir de datos. Según las directrices de la Comisión Europea (2025) sobre la definición de sistema de IA, podría quedar fuera de ella. Aun así, se le aplican **voluntariamente** las garantías de los sistemas de alto riesgo del anexo III (empleo) (`LEG-005b`):

| Garantía | Cómo se cumple |
|---|---|
| Transparencia y explicación | Factores en lenguaje llano en la ficha y metodología pública con sus fuentes (`RF-034`) |
| Supervisión humana | Solo alerta; las alertas las gestiona una persona (`LEG-005`) |
| Registro de actividad | Auditoría de cada consulta y de cada recálculo (`LEG-004`) |
| Gestión de sesgos | Revisión trimestral por grupos (`LEG-015`, apartado 7) |
| Gobernanza del dato | Solo datos laborales legítimos; nada de salud ni de bienestar individual |

## 6. Fuentes

| Id | Referencia |
|---|---|
| S1 | Work Institute, *2025 Retention Report: Employee Retention Truths in Today's Workplace* (y ediciones 2018–2023). Hasta un 40 % de la rotación ocurre en el primer año. |
| S2 | Griffeth, R. W., Hom, P. W. y Gaertner, S. (2000). A meta-analysis of antecedents and correlates of employee turnover. *Journal of Management, 26*(3), 463–488. |
| S3 | Berry, C. M., Lelchook, A. M. y Clark, M. A. (2012). A meta-analysis of the interrelationships between employee lateness, absenteeism, and turnover. *Journal of Organizational Behavior, 33*(5), 678–699. Absentismo–rotación ≈ 0,25; retrasos–rotación ≈ 0,01. |
| S4 | *Frontiers in Psychology* (2022), intención de abandono en trabajadores temporales y permanentes; De Cuyper et al. (2009), contrato psicológico de los temporales. |
| S5 | LinkedIn Learning, *Workplace Learning Report* (2018–2019). El 94 % afirma que se quedaría más tiempo si la empresa invirtiera en su formación (dato declarativo). |
| S6 | Estudios en personal sanitario a turnos (*BMC Nursing*, 2022; revisión en *Frontiers in Psychology*, 2021). Evidencia de otro sector. |
| S7 | Gallup y Workhuman (2024), estudio longitudinal 2022–2024 con cerca de 3.500 empleados: un 45 % menos de probabilidad de marcharse a los dos años con reconocimiento de calidad. |

## 7. Revisión de sesgos (`LEG-015`)

**Procedimiento.** Cada trimestre (el día 1 de enero, abril, julio y octubre, a las 04:30) `cli/bias_report.php` calcula, para cada empresa con plan Completo, la distribución de niveles por **equipo, puesto, turno y tipo de contrato**. Se marcan los grupos de **5 o más personas** cuyo porcentaje en nivel alto se aparta **más de 20 puntos porcentuales** del total de la empresa. El informe se guarda en `storage/logs/sesgos_AAAAMM.md`; solo contiene recuentos por grupo, sin nombres. El mismo análisis está en la aplicación (IA de RR. HH. → Sesgos, `GET /v1/risk/bias`).

**Cómo se interpreta.** Una diferencia en turno o tipo de contrato es en parte esperable, porque son factores del propio modelo (F3 y F5); el informe lo indica. Una diferencia en un grupo que **no** es factor del modelo (un equipo o un puesto) exige que una persona revise si la explica un factor legítimo o si hay un sesgo. La conclusión se anota en el registro de abajo.

**Registro de revisiones**

| Fecha | Ámbito | Grupos marcados | Conclusión | Responsable |
|---|---|---|---|---|
| 12/10/2026 | Demo «Reparto Rápido Castilla S.L.» (48 personas, 12,5 % en alto) | Turno NOCHE, +20,8 pp (esperable, F5). Equipo Nocturno, +20,8 pp | El equipo Nocturno coincide al 100 % con el turno de noche (F5) y concentra incorporaciones recientes con contrato temporal (F1, F3). La diferencia se explica por factores del modelo; no se cambian pesos. Se vigilará en el piloto con datos reales. | Equipo Pyme Hub |

## 8. Validación

**Hoy (MVP).** No hay datos reales de bajas para calibrar. La validación es:

1. **De contenido:** cada peso se justifica por la fuerza de la evidencia (apartados 3 y 6).
2. **De casos de control:** apartado 3, cubiertos por `tests/smoke_test.sh`, que comprueba 30 + 25 + 15 + 10 = 80 y que los retrasos no puntúan.
3. **Retrospectiva:** el panel muestra, para las bajas voluntarias desde el alta de la empresa en Pyme Hub, el nivel de su última puntuación antes de irse. Con pocas bajas es solo orientativa. En la demo, de 6 bajas voluntarias, 3 estaban en nivel alto y 3 en medio.

**Línea base de impacto (`RF-037`).** La empresa registra la plantilla media, las bajas voluntarias y los días de ausencia injustificada de los 12 meses anteriores al alta (Configuración, `PUT /v1/baseline`). El panel compara la rotación voluntaria y las ausencias anualizadas desde el alta con esa línea base. El objetivo del piloto es una reducción relativa del 15 % a 12 meses (Anexo 16.4 del TFM). Es una comparación real antes/después, no una predicción de impacto, y no atribuye causalidad. Con menos de 90 días desde el alta se marca como no representativa. Las ausencias deben medirse igual que en la aplicación, sin bajas médicas.

## 9. Limitaciones

- Es un **modelo inicial basado en literatura**, no una calibración estadística: los pesos ordenan la fuerza de la evidencia, pero no son coeficientes estimados.
- S1, S5 y S7 proceden de Estados Unidos o de muestras internacionales, no del transporte español. El piloto debe ajustarlo al sector y al país, y conviene contrastarlo con las encuestas de campo del TFM.
- F6 es una hipótesis del equipo sin fuente directa: se validará con los datos del piloto.
- La puntuación depende de que la empresa registre las incidencias. Si no registra reconocimientos, F7 no actúa y las puntuaciones salen más altas.
- No recoge motivos de salida externos al trabajo, como un cambio de domicilio, mejores ofertas o la situación familiar.
- Los umbrales de nivel son convenciones (`RF-033`), no puntos de corte optimizados.

## 10. Evolución (`RF-036`)

En la fase 2 (meses 17–18 del cronograma, Anexo F del TFM), con 12 meses de datos reales de bajas de los clientes piloto:

- se sustituirán los pesos por los coeficientes de una regresión logística validada (AUC, calibración y análisis por grupos);
- se mantendrán la explicación por factor y estas garantías;
- el cambio se publicará como una versión nueva de las reglas (v2.x), con esta hoja actualizada.

## 11. Historial

| Versión | Fecha | Cambio |
|---|---|---|
| v1 | 09/2026 | Borrador: antigüedad 25, ausencias 15, retrasos 10, participación en el check-in |
| **v1.1** | 27/09/2026 | Recalibrada según la evidencia: antigüedad 30/15 hasta 365 días; ausencias 25/10 en 60 días; retrasos a 0; participación en el check-in eliminada; reconocimiento −15; niveles 30/55 (requisitos §9.3) |
| v1.1 | 12/10/2026 | Implementada en la API v0.6.0: puntuación diaria, alertas, ficha explicada, metodología, sesgos, retrospectiva y línea base |
