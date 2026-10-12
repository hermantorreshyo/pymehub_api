<?php
declare(strict_types=1);

/**
 * Reglas del motor de riesgo de rotación (§9.3 de los requisitos, v1.1).
 *
 * Ponderación ordinal justificada por la fuerza de la evidencia, no calibración estadística (§9.2, §9.5).
 * Cambiar un peso, una condición o una fuente exige subir `version` (RF-032): cada puntuación guarda
 * la versión con la que se calculó para poder explicarla y reproducirla.
 * Solo datos que un gerente ya maneja legítimamente; nada de salud ni del check-in (LEG-013).
 */
return [
    'version' => 'v1.1',
    'niveles' => ['MEDIO' => 30, 'ALTO' => 55], // bajo 0–29 · medio 30–54 · alto 55–100 (RF-033)
    'factores' => [
        'F1' => [
            'nombre' => 'Antigüedad', 'evidencia' => 'Fuerte', 'fuentes' => ['S1', 'S2'],
            'tramos' => [['max_dias' => 89, 'puntos' => 30], ['max_dias' => 365, 'puntos' => 15]],
        ],
        'F2' => [
            'nombre' => 'Ausencias injustificadas', 'evidencia' => 'Fuerte (meta-analítica)', 'fuentes' => ['S3'],
            'ventana_dias' => 60, 'tramos' => [['min' => 2, 'puntos' => 25], ['min' => 1, 'puntos' => 10]],
        ],
        'F3' => [
            'nombre' => 'Tipo de contrato', 'evidencia' => 'Moderada', 'fuentes' => ['S4'],
            'contratos' => ['TEMPORAL', 'ETT'], 'puntos' => 15,
        ],
        'F4' => [
            'nombre' => 'Formación vencida', 'evidencia' => 'Débil (declarativa)', 'fuentes' => ['S5'],
            'puntos' => 10,
        ],
        'F5' => [
            'nombre' => 'Turno', 'evidencia' => 'Moderada, indirecta (otro sector)', 'fuentes' => ['S6'],
            'turnos' => ['NOCHE', 'ROTATIVO'], 'puntos' => 10,
        ],
        'F6' => [
            'nombre' => 'Siniestros o quejas de cliente', 'evidencia' => 'Hipótesis del equipo, sin fuente directa', 'fuentes' => [],
            'ventana_dias' => 60, 'puntos' => 10,
        ],
        'F7' => [
            'nombre' => 'Reconocimiento', 'evidencia' => 'Moderada-fuerte (longitudinal)', 'fuentes' => ['S7'],
            'ventana_dias' => 90, 'puntos' => -15,
        ],
    ],
    'excluidos' => [
        ['nombre' => 'Retrasos', 'motivo' => 'Se registran, pero no puntúan: la evidencia meta-analítica no encuentra relación con la baja voluntaria.', 'fuentes' => ['S3']],
        ['nombre' => 'Participación en el check-in', 'motivo' => 'Excluida por decisión ética y legal: si no responder subiera el riesgo, el check-in dejaría de ser voluntario (LEG-013).', 'fuentes' => []],
        ['nombre' => 'Datos de bienestar individuales y de salud', 'motivo' => 'Nunca entran en el cálculo (§9.2). Las bajas médicas no se registran (LEG-012).', 'fuentes' => []],
    ],
    'fuentes' => [
        'S1' => 'Work Institute, 2025 Retention Report: Employee Retention Truths in Today\'s Workplace (y ediciones 2018–2023). Hasta un 40 % de la rotación ocurre en el primer año.',
        'S2' => 'Griffeth, R. W., Hom, P. W. y Gaertner, S. (2000). A meta-analysis of antecedents and correlates of employee turnover. Journal of Management, 26(3), 463–488.',
        'S3' => 'Berry, C. M., Lelchook, A. M. y Clark, M. A. (2012). A meta-analysis of the interrelationships between employee lateness, absenteeism, and turnover. Journal of Organizational Behavior, 33(5), 678–699. Absentismo–rotación ≈ 0,25; retrasos–rotación ≈ 0,01.',
        'S4' => 'Frontiers in Psychology (2022), intención de abandono en trabajadores temporales y permanentes; De Cuyper et al. (2009), contrato psicológico de los temporales.',
        'S5' => 'LinkedIn Learning, Workplace Learning Report (2018–2019). El 94 % afirma que se quedaría más tiempo si la empresa invirtiera en su formación (dato declarativo).',
        'S6' => 'Estudios en personal sanitario a turnos (BMC Nursing, 2022; revisión en Frontiers in Psychology, 2021). Evidencia de otro sector.',
        'S7' => 'Gallup y Workhuman (2024), estudio longitudinal 2022–2024 con cerca de 3.500 empleados: un 45 % menos de probabilidad de marcharse a los dos años con reconocimiento de calidad.',
    ],
    'limitaciones' => [
        'Es un modelo inicial basado en literatura, no una calibración estadística: los pesos ordenan la fuerza de la evidencia.',
        'S1, S5 y S7 proceden de Estados Unidos o de muestras internacionales, no del transporte español; el piloto debe ajustarlo al sector y al país.',
        'F6 es una hipótesis del equipo sin fuente directa: se validará con los datos del piloto.',
        'Con 12 meses de bajas reales de los clientes piloto se sustituirán los pesos por una regresión logística validada (AUC, calibración), manteniendo la explicación por factor (RF-036).',
    ],
];
