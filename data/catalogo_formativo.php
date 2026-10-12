<?php
declare(strict_types=1);

/**
 * Catálogo global de micro-módulos (RF-040, RF-041). Propietario: interlocutor PLATAFORMA.
 *
 * Cada módulo: 3–5 minutos, bloques breves (texto, lista, consejo clave, ilustración)
 * y un cuestionario de 2–4 preguntas con 3 opciones. Aprobado con ≥ 70 % (RF-044).
 * Se sincroniza con `php cli/catalog_sync.php` (también lo hacen migrate.php, el instalador
 * y la carga de demostración): se identifica por `codigo` y, si cambia el contenido, sube
 * la versión del módulo sin tocar el avance de quien ya lo cursa.
 *
 * Las ilustraciones son composiciones de marca locales, una por categoría (RNF-048).
 */
return [

    // ---------------------------------------------------------------- Conducción y seguridad vial
    [
        'codigo' => 'CONDUCCION_EFICIENTE',
        'titulo' => 'Conducción eficiente',
        'resumen' => 'Gasta menos combustible y llega igual de rápido: anticipación, marchas largas y un vehículo a punto.',
        'categoria' => 'CONDUCCION', 'nivel' => 'BASICO', 'duracion' => 4,
        'puestos' => ['CONDUCTOR', 'REPARTIDOR'],
        'etiquetas' => ['combustible', 'medio ambiente', 'ahorro', 'furgoneta'],
        'contenido' => [
            'objetivos' => [
                'Anticipar el tráfico para frenar y acelerar menos.',
                'Usar la marcha adecuada en cada momento.',
                'Revisar lo que más influye en el consumo antes de salir.',
            ],
            'bloques' => [
                ['tipo' => 'imagen', 'ilustracion' => 'CONDUCCION', 'alt' => 'Furgoneta de reparto circulando de forma fluida por la ciudad'],
                ['tipo' => 'texto', 'titulo' => 'Mira lejos, conduce suave', 'texto' => 'La mayor parte del combustible se pierde en acelerones y frenazos. Si miras dos o tres vehículos por delante, ves antes el semáforo en rojo o el atasco y puedes levantar el pie con tiempo. Rodando sin acelerar y con una marcha metida, el motor apenas consume.'],
                ['tipo' => 'lista', 'titulo' => 'Cinco hábitos que ahorran', 'items' => [
                    'Cambia de marcha pronto: entre 2.000 y 2.500 revoluciones en diésel.',
                    'Mantén una velocidad constante y deja distancia con el de delante.',
                    'Apaga el motor en paradas de más de un minuto si es seguro.',
                    'Revisa la presión de los neumáticos cada semana: con poca presión se consume más.',
                    'No lleves peso que no necesitas en la furgoneta.',
                ]],
                ['tipo' => 'consejo', 'texto' => 'Conducir de forma eficiente no es ir más despacio: es ir más fluido. Llegas a la misma hora, con menos cansancio y menos gasto.'],
            ],
        ],
        'preguntas' => [
            ['enunciado' => '¿Qué hábito ahorra más combustible en ciudad?', 'opciones' => ['Anticipar y evitar acelerones y frenazos', 'Llevar el motor siempre muy revolucionado', 'Frenar a última hora en cada semáforo'], 'correcta' => 0],
            ['enunciado' => 'En un motor diésel, ¿cuándo conviene cambiar a una marcha más larga?', 'opciones' => ['Al llegar a 4.000 revoluciones', 'Entre 2.000 y 2.500 revoluciones', 'Solo cuando el motor empieza a vibrar'], 'correcta' => 1],
            ['enunciado' => '¿Cada cuánto conviene revisar la presión de los neumáticos?', 'opciones' => ['Una vez al año, en la revisión', 'Solo si se ve una rueda baja', 'Cada semana'], 'correcta' => 2],
        ],
    ],
    [
        'codigo' => 'SEGURIDAD_VIAL_CIUDAD',
        'titulo' => 'Seguridad vial en ciudad',
        'resumen' => 'Ángulos muertos, ciclistas y patinetes, paradas para entregar y los límites de velocidad urbanos.',
        'categoria' => 'CONDUCCION', 'nivel' => 'BASICO', 'duracion' => 5,
        'puestos' => ['CONDUCTOR', 'REPARTIDOR'],
        'etiquetas' => ['seguridad vial', 'siniestros', 'ciclistas', 'ciudad'],
        'contenido' => [
            'objetivos' => [
                'Conocer los ángulos muertos de tu furgoneta.',
                'Compartir la calle con ciclistas, patinetes y peatones.',
                'Parar a entregar sin poner a nadie en peligro.',
            ],
            'bloques' => [
                ['tipo' => 'imagen', 'ilustracion' => 'CONDUCCION', 'alt' => 'Furgoneta en un cruce urbano con un carril bici al lado'],
                ['tipo' => 'texto', 'titulo' => 'Lo que no ves desde el asiento', 'texto' => 'Una furgoneta tiene ángulos muertos grandes junto a la puerta del copiloto y detrás. Antes de girar a la derecha o de dar marcha atrás, comprueba los espejos dos veces: un ciclista o un patinete puede estar justo donde no lo ves. Si no estás seguro al maniobrar marcha atrás, bájate a mirar.'],
                ['tipo' => 'lista', 'titulo' => 'Reglas que más se olvidan', 'items' => [
                    'En calles de un solo carril por sentido, el límite general es 30 km/h.',
                    'Al adelantar a una bici, deja al menos 1,5 metros de separación.',
                    'Parar en doble fila o en un paso de peatones está prohibido aunque sea un minuto.',
                    'El móvil, solo con soporte y sin manipularlo en marcha.',
                ]],
                ['tipo' => 'consejo', 'texto' => 'Una entrega con prisa nunca justifica una maniobra arriesgada. Si no hay sitio para parar bien, da otra vuelta.'],
            ],
        ],
        'preguntas' => [
            ['enunciado' => '¿Cuál es el límite general en una calle con un solo carril por sentido?', 'opciones' => ['30 km/h', '50 km/h', '40 km/h'], 'correcta' => 0],
            ['enunciado' => '¿Qué separación mínima hay que dejar al adelantar a una bicicleta?', 'opciones' => ['50 centímetros', '1,5 metros', 'La que permita el carril'], 'correcta' => 1],
            ['enunciado' => 'Vas a girar a la derecha y hay carril bici. ¿Qué haces?', 'opciones' => ['Giro rápido para no frenar el tráfico', 'Pito para avisar y giro', 'Compruebo los espejos dos veces antes de girar'], 'correcta' => 2],
        ],
    ],
    [
        'codigo' => 'CONDUCCION_NOCTURNA',
        'titulo' => 'Conducción nocturna segura',
        'resumen' => 'Cómo reconocer la fatiga a tiempo, usar bien las luces y organizar las pausas en el turno de noche.',
        'categoria' => 'CONDUCCION', 'nivel' => 'INTERMEDIO', 'duracion' => 5,
        'puestos' => ['CONDUCTOR'],
        'etiquetas' => ['noche', 'fatiga', 'turnos', 'seguridad vial'],
        'contenido' => [
            'objetivos' => [
                'Detectar las señales de fatiga y de sueño al volante.',
                'Usar las luces y mantener la visibilidad.',
                'Planificar pausas en el turno de noche.',
            ],
            'bloques' => [
                ['tipo' => 'imagen', 'ilustracion' => 'CONDUCCION', 'alt' => 'Furgoneta con las luces encendidas en una carretera de noche'],
                ['tipo' => 'texto', 'titulo' => 'El sueño avisa antes de llegar', 'texto' => 'Entre las 3 y las 6 de la madrugada el cuerpo pide dormir aunque hayas descansado. Bostezar a menudo, parpadear despacio, no recordar los últimos kilómetros o salirte un poco del carril son avisos claros. Un microsueño de cuatro segundos a 80 km/h son casi 90 metros sin control.'],
                ['tipo' => 'lista', 'titulo' => 'En tu turno de noche', 'items' => [
                    'Haz una pausa de 15 a 20 minutos cada dos horas de conducción.',
                    'Si tienes sueño, para en un lugar seguro: una siesta de 15 minutos funciona mejor que el café.',
                    'Mantén limpios el parabrisas y los faros, por dentro y por fuera.',
                    'Usa las largas solo cuando no deslumbres a nadie.',
                ]],
                ['tipo' => 'consejo', 'texto' => 'Bajar la ventanilla o subir la música no quita el sueño. La única solución es parar y descansar.'],
            ],
        ],
        'preguntas' => [
            ['enunciado' => '¿Cuál de estas es una señal de fatiga al volante?', 'opciones' => ['No recordar los últimos kilómetros', 'Tener hambre', 'Ir con la radio apagada'], 'correcta' => 0],
            ['enunciado' => 'Notas sueño en mitad de la ruta. ¿Qué es lo más eficaz?', 'opciones' => ['Bajar la ventanilla y seguir', 'Parar en un sitio seguro y descansar unos 15 minutos', 'Tomar otro café y acelerar para acabar antes'], 'correcta' => 1],
            ['enunciado' => '¿Cada cuánto conviene parar en una ruta nocturna?', 'opciones' => ['Solo al terminar la ruta', 'Cada cuatro horas', 'Cada dos horas, unos 15 a 20 minutos'], 'correcta' => 2],
        ],
    ],
    [
        'codigo' => 'VEHICULO_ELECTRICO',
        'titulo' => 'Uso del vehículo eléctrico de reparto',
        'resumen' => 'Autonomía, frenada regenerativa y recarga segura para sacar el máximo a la furgoneta eléctrica.',
        'categoria' => 'CONDUCCION', 'nivel' => 'BASICO', 'duracion' => 4,
        'puestos' => ['CONDUCTOR', 'REPARTIDOR'],
        'etiquetas' => ['eléctrico', 'autonomía', 'recarga', 'medio ambiente'],
        'contenido' => [
            'objetivos' => [
                'Entender qué hace subir o bajar la autonomía.',
                'Aprovechar la frenada regenerativa.',
                'Recargar de forma segura.',
            ],
            'bloques' => [
                ['tipo' => 'imagen', 'ilustracion' => 'CONDUCCION', 'alt' => 'Furgoneta eléctrica conectada a un punto de recarga'],
                ['tipo' => 'texto', 'titulo' => 'La autonomía depende de ti', 'texto' => 'Un vehículo eléctrico recupera energía cada vez que levantas el pie del acelerador: es la frenada regenerativa. En ciudad, si anticipas, puedes frenar casi sin tocar el pedal y ganar kilómetros. El frío, la calefacción fuerte, la carga pesada y la velocidad alta en vía rápida son lo que más autonomía consume.'],
                ['tipo' => 'lista', 'titulo' => 'Recarga segura', 'items' => [
                    'Revisa que el cable y el conector no estén dañados antes de enchufar.',
                    'No uses alargadores ni adaptadores caseros.',
                    'Con el cable conectado, el vehículo no se mueve: no fuerces el arranque.',
                    'Avisa si un punto de recarga de la empresa falla, no intentes repararlo.',
                ]],
                ['tipo' => 'consejo', 'texto' => 'Empieza la ruta con la batería cargada y la cabina ya climatizada mientras está enchufada: ese gasto sale de la red, no de tu autonomía.'],
            ],
        ],
        'preguntas' => [
            ['enunciado' => '¿Qué es la frenada regenerativa?', 'opciones' => ['El vehículo recupera energía al levantar el pie del acelerador', 'Un freno de emergencia automático', 'El sistema que evita derrapar'], 'correcta' => 0],
            ['enunciado' => '¿Cuál de estos factores reduce más la autonomía?', 'opciones' => ['Conducir anticipando en ciudad', 'Ir rápido en vía rápida con calefacción fuerte', 'Climatizar la cabina mientras se carga'], 'correcta' => 1],
            ['enunciado' => 'El conector del punto de recarga tiene el plástico roto. ¿Qué haces?', 'opciones' => ['Lo sujeto con cinta y cargo', 'Uso un adaptador casero', 'No lo uso y aviso a la empresa'], 'correcta' => 2],
        ],
    ],

    // ---------------------------------------------------------------- Almacén y carga
    [
        'codigo' => 'MANIPULACION_CARGAS',
        'titulo' => 'Manipulación manual de cargas',
        'resumen' => 'Levantar, transportar y descargar bultos sin lesionarte la espalda.',
        'categoria' => 'ALMACEN', 'nivel' => 'BASICO', 'duracion' => 4,
        'puestos' => ['MOZO_ALMACEN', 'REPARTIDOR', 'CONDUCTOR'],
        'etiquetas' => ['espalda', 'prevención', 'bultos', 'almacén'],
        'contenido' => [
            'objetivos' => [
                'Aplicar la técnica correcta para levantar un bulto.',
                'Saber cuándo pedir ayuda o un medio mecánico.',
                'Evitar los giros de tronco con carga.',
            ],
            'bloques' => [
                ['tipo' => 'imagen', 'ilustracion' => 'ALMACEN', 'alt' => 'Persona levantando una caja con la espalda recta y las piernas flexionadas'],
                ['tipo' => 'texto', 'titulo' => 'Las piernas levantan, la espalda acompaña', 'texto' => 'Acércate al bulto, separa los pies a la anchura de los hombros y flexiona las rodillas manteniendo la espalda recta. Sujeta la carga con firmeza, pégala al cuerpo y levántate con la fuerza de las piernas, sin tirones. Para girar, mueve los pies: nunca gires el tronco con peso.'],
                ['tipo' => 'lista', 'titulo' => 'Antes de levantar', 'items' => [
                    'Calcula el peso: como referencia general, no más de 25 kg por persona.',
                    'Si pesa más, es voluminoso o inestable, pide ayuda o usa el transpalé.',
                    'Despeja el camino y mira dónde vas a dejarlo.',
                    'Usa guantes si el embalaje tiene bordes o flejes.',
                ]],
                ['tipo' => 'consejo', 'texto' => 'Las lesiones de espalda se acumulan con los años. Cada levantamiento bien hecho cuenta.'],
            ],
        ],
        'preguntas' => [
            ['enunciado' => '¿Cómo se levanta correctamente un bulto del suelo?', 'opciones' => ['Rodillas flexionadas, espalda recta y carga pegada al cuerpo', 'Piernas rectas y espalda inclinada', 'Con un tirón rápido para no cansarse'], 'correcta' => 0],
            ['enunciado' => 'Tienes que dejar una caja a tu lado. ¿Qué haces?', 'opciones' => ['Giro el tronco con la caja en las manos', 'Muevo los pies para girar todo el cuerpo', 'La lanzo con cuidado'], 'correcta' => 1],
            ['enunciado' => 'El bulto pesa unos 35 kg. ¿Qué es lo correcto?', 'opciones' => ['Levantarlo solo si me veo fuerte', 'Arrastrarlo por el suelo', 'Pedir ayuda o usar un medio mecánico'], 'correcta' => 2],
        ],
    ],
    [
        'codigo' => 'TRANSPALE_SEGURO',
        'titulo' => 'Uso seguro del transpalé',
        'resumen' => 'Revisión previa, manejo en rampas y pasillos, y los errores que causan más accidentes.',
        'categoria' => 'ALMACEN', 'nivel' => 'BASICO', 'duracion' => 4,
        'puestos' => ['MOZO_ALMACEN'],
        'etiquetas' => ['transpalé', 'almacén', 'prevención', 'palés'],
        'contenido' => [
            'objetivos' => [
                'Revisar el transpalé antes de usarlo.',
                'Moverte con carga por pasillos y rampas.',
                'Conocer lo que nunca se debe hacer.',
            ],
            'bloques' => [
                ['tipo' => 'imagen', 'ilustracion' => 'ALMACEN', 'alt' => 'Transpalé con un palé cargado en un pasillo de almacén'],
                ['tipo' => 'texto', 'titulo' => 'Revisión de 30 segundos', 'texto' => 'Antes de cargar, comprueba que las ruedas giran bien, que el freno funciona y que la palanca sube y baja la horquilla. Si es eléctrico, revisa la batería y el pulsador de emergencia. Introduce la horquilla del todo bajo el palé y levántalo solo lo necesario para despegarlo del suelo.'],
                ['tipo' => 'lista', 'titulo' => 'Nunca', 'items' => [
                    'Subirse al transpalé ni llevar a nadie encima.',
                    'Superar la carga máxima que indica la placa.',
                    'Bajar una rampa con la carga delante: en pendiente, la carga va siempre del lado de abajo, y tú detrás, controlándola.',
                    'Dejarlo en un pasillo o delante de una salida de emergencia.',
                ]],
                ['tipo' => 'consejo', 'texto' => 'Empuja mejor que tirar cuando puedas: ves por dónde vas y tus pies quedan lejos de las ruedas.'],
            ],
        ],
        'preguntas' => [
            ['enunciado' => '¿Qué revisas antes de usar el transpalé?', 'opciones' => ['Ruedas, freno y que la horquilla sube y baja', 'Solo que esté limpio', 'Nada si lo usó un compañero antes'], 'correcta' => 0],
            ['enunciado' => '¿Está permitido subirse al transpalé para ir más rápido?', 'opciones' => ['Sí, si el pasillo está vacío', 'No, nunca', 'Solo con el transpalé eléctrico'], 'correcta' => 1],
            ['enunciado' => '¿A qué altura se levanta un palé para moverlo?', 'opciones' => ['Lo más alto posible', 'A la altura de la cintura', 'Solo lo necesario para despegarlo del suelo'], 'correcta' => 2],
        ],
    ],
    [
        'codigo' => 'ESTIBA_SUJECION',
        'titulo' => 'Estiba y sujeción de la carga',
        'resumen' => 'Cómo colocar y sujetar la mercancía en la furgoneta para que no se mueva ni se dañe.',
        'categoria' => 'ALMACEN', 'nivel' => 'INTERMEDIO', 'duracion' => 5,
        'puestos' => ['CONDUCTOR', 'REPARTIDOR', 'MOZO_ALMACEN'],
        'etiquetas' => ['estiba', 'carga', 'furgoneta', 'daños'],
        'contenido' => [
            'objetivos' => [
                'Repartir el peso dentro del vehículo.',
                'Sujetar la carga con los medios adecuados.',
                'Ordenar los bultos según la ruta.',
            ],
            'bloques' => [
                ['tipo' => 'imagen', 'ilustracion' => 'ALMACEN', 'alt' => 'Interior de una furgoneta con cajas ordenadas y cinchas'],
                ['tipo' => 'texto', 'titulo' => 'Lo pesado, abajo y delante', 'texto' => 'Coloca los bultos más pesados en el suelo, pegados a la mampara y centrados entre los ejes. Encima van los ligeros. Así el vehículo frena y gira mejor. La normativa de tráfico exige que la carga no pueda desplazarse ni caer: si se mueve en un frenazo, puede atravesar la mampara o romper la mercancía.'],
                ['tipo' => 'lista', 'titulo' => 'Sujeta y ordena', 'items' => [
                    'Usa cinchas, barras o redes ancladas a los puntos de amarre, nunca cuerdas sueltas.',
                    'Rellena los huecos para que los bultos no bailen.',
                    'Carga en orden inverso a la ruta: lo último en entregarse, al fondo.',
                    'No tapes la visibilidad ni superes la masa máxima autorizada.',
                ]],
                ['tipo' => 'consejo', 'texto' => 'Una buena estiba también ahorra tiempo: encuentras cada paquete sin vaciar media furgoneta.'],
            ],
        ],
        'preguntas' => [
            ['enunciado' => '¿Dónde van los bultos más pesados?', 'opciones' => ['En el suelo, pegados a la mampara y centrados', 'Arriba del todo para que no aplasten', 'Junto a las puertas traseras'], 'correcta' => 0],
            ['enunciado' => '¿En qué orden se carga la furgoneta?', 'opciones' => ['Por tamaño, de mayor a menor', 'En orden inverso a la ruta: lo último en entregarse, al fondo', 'Como vayan llegando del almacén'], 'correcta' => 1],
            ['enunciado' => '¿Cómo se sujeta la carga?', 'opciones' => ['Con cuerdas atadas a las manetas', 'No hace falta si va llena', 'Con cinchas o barras ancladas a los puntos de amarre'], 'correcta' => 2],
        ],
    ],

    // ---------------------------------------------------------------- Cliente y entrega
    [
        'codigo' => 'ATENCION_CLIENTE',
        'titulo' => 'Atención al cliente en la entrega',
        'resumen' => 'Los tres minutos que el cliente recuerda: saludo, identificación y una entrega bien cerrada.',
        'categoria' => 'CLIENTE', 'nivel' => 'BASICO', 'duracion' => 4,
        'puestos' => ['REPARTIDOR', 'CONDUCTOR'],
        'etiquetas' => ['cliente', 'entrega', 'imagen', 'quejas'],
        'contenido' => [
            'objetivos' => [
                'Causar buena impresión en la entrega.',
                'Cerrar la entrega con la prueba correcta.',
                'Evitar las quejas más habituales.',
            ],
            'bloques' => [
                ['tipo' => 'imagen', 'ilustracion' => 'CLIENTE', 'alt' => 'Repartidor entregando un paquete en la puerta de un cliente'],
                ['tipo' => 'texto', 'titulo' => 'Eres la cara de la empresa', 'texto' => 'Para el cliente, tú eres la empresa que le envía el paquete. Saluda, di de qué empresa vienes y confirma el nombre del destinatario antes de entregar. Entrega el paquete en mano, en buen estado, y registra la prueba de entrega en el momento: firma, foto o código, según indique la app.'],
                ['tipo' => 'lista', 'titulo' => 'Lo que más quejas evita', 'items' => [
                    'Avisar si vas a llegar fuera de la franja prevista.',
                    'No dejar paquetes en el portal o el felpudo sin autorización.',
                    'Tratar con cuidado la mercancía, también al bajarla de la furgoneta.',
                    'Una despedida amable, aunque haya prisa.',
                ]],
                ['tipo' => 'consejo', 'texto' => 'Un "buenos días" y decir de qué empresa vienes cuesta dos segundos y cambia cómo te recibe el cliente.'],
            ],
        ],
        'preguntas' => [
            ['enunciado' => '¿Qué haces antes de entregar el paquete?', 'opciones' => ['Confirmar el nombre del destinatario', 'Pedir que firme sin mirar', 'Dejarlo en el suelo y marcharme'], 'correcta' => 0],
            ['enunciado' => 'No hay nadie en casa y no tienes instrucciones. ¿Puedes dejarlo en el felpudo?', 'opciones' => ['Sí, si el portal está cerrado', 'No, sin autorización no', 'Sí, con una foto'], 'correcta' => 1],
            ['enunciado' => '¿Cuándo se registra la prueba de entrega?', 'opciones' => ['Al final de la ruta, todas juntas', 'Al día siguiente', 'En el momento de la entrega'], 'correcta' => 2],
        ],
    ],
    [
        'codigo' => 'INCIDENCIAS_RECHAZOS',
        'titulo' => 'Gestión de incidencias y rechazos',
        'resumen' => 'Qué hacer con un cliente ausente, una dirección errónea o un paquete dañado o rechazado.',
        'categoria' => 'CLIENTE', 'nivel' => 'INTERMEDIO', 'duracion' => 4,
        'puestos' => ['REPARTIDOR', 'CONDUCTOR', 'COORDINADOR'],
        'etiquetas' => ['incidencias', 'rechazos', 'ausente', 'daños'],
        'contenido' => [
            'objetivos' => [
                'Registrar cada incidencia con el motivo correcto.',
                'Actuar ante un paquete dañado o rechazado.',
                'Dejar la información que necesita el siguiente intento.',
            ],
            'bloques' => [
                ['tipo' => 'imagen', 'ilustracion' => 'CLIENTE', 'alt' => 'Repartidor revisando un paquete con el móvil en la mano'],
                ['tipo' => 'texto', 'titulo' => 'Una incidencia bien registrada es media solución', 'texto' => 'Si el cliente no está, la dirección no existe o no puedes acceder, marca el motivo exacto en la app y deja aviso de paso si tu empresa lo usa. Con un motivo claro, atención al cliente puede reprogramar sin llamarte. "Ausente" y "dirección incorrecta" se resuelven de forma muy distinta.'],
                ['tipo' => 'lista', 'titulo' => 'Paquete dañado o rechazado', 'items' => [
                    'Si ves el embalaje dañado, haz una foto antes de entregarlo.',
                    'Si el cliente lo rechaza, anota el motivo que te da, con sus palabras.',
                    'No abras el paquete ni discutas sobre su contenido.',
                    'Devuélvelo al almacén con su incidencia registrada.',
                ]],
                ['tipo' => 'consejo', 'texto' => 'Escribe la incidencia como si la fuera a leer alguien que no ha estado allí: qué pasó, cuándo y qué hiciste.'],
            ],
        ],
        'preguntas' => [
            ['enunciado' => 'Ves que la caja llega con una esquina aplastada. ¿Qué haces primero?', 'opciones' => ['Hago una foto antes de entregarla', 'La entrego sin decir nada', 'La abro para revisar el contenido'], 'correcta' => 0],
            ['enunciado' => '¿Por qué es importante marcar el motivo exacto de la incidencia?', 'opciones' => ['Porque lo pide la normativa de tráfico', 'Para que el siguiente intento se pueda reprogramar bien', 'No es importante si se deja aviso'], 'correcta' => 1],
            ['enunciado' => 'El cliente rechaza el paquete. ¿Qué anotas?', 'opciones' => ['Nada, solo lo devuelvo', 'Mi opinión sobre el cliente', 'El motivo que da el cliente'], 'correcta' => 2],
        ],
    ],
    [
        'codigo' => 'CLIENTES_DIFICILES',
        'titulo' => 'Comunicación con clientes difíciles',
        'resumen' => 'Mantener la calma, escuchar y saber cuándo terminar la conversación y escalarla.',
        'categoria' => 'CLIENTE', 'nivel' => 'INTERMEDIO', 'duracion' => 4,
        'puestos' => ['REPARTIDOR', 'CONDUCTOR', 'COORDINADOR'],
        'etiquetas' => ['comunicación', 'conflictos', 'quejas', 'calma'],
        'contenido' => [
            'objetivos' => [
                'Rebajar la tensión con escucha activa.',
                'Responder sin tomarlo como algo personal.',
                'Saber cuándo cortar y a quién avisar.',
            ],
            'bloques' => [
                ['tipo' => 'imagen', 'ilustracion' => 'CLIENTE', 'alt' => 'Dos personas conversando con calma en una puerta'],
                ['tipo' => 'texto', 'titulo' => 'Escucha primero, explica después', 'texto' => 'Un cliente enfadado casi nunca está enfadado contigo: lo está con el retraso o con el problema. Déjale terminar, repite con tus palabras lo que te dice ("entiendo que lo esperaba ayer") y explica lo que sí puedes hacer. Habla despacio y con un tono más bajo que el suyo.'],
                ['tipo' => 'lista', 'titulo' => 'Frases que ayudan', 'items' => [
                    '"Entiendo que es una molestia, voy a ver cómo lo resolvemos."',
                    '"Lo que puedo hacer ahora es…"',
                    '"Dejo registrada su queja para que le llamen."',
                ]],
                ['tipo' => 'consejo', 'texto' => 'Si hay insultos o amenazas, no tienes que aguantarlo: termina la entrega con educación, márchate y avisa a tu coordinador.'],
            ],
        ],
        'preguntas' => [
            ['enunciado' => 'Un cliente te recibe gritando por un retraso. ¿Qué haces primero?', 'opciones' => ['Le dejo terminar y le escucho', 'Le explico que no es culpa mía', 'Le pido que baje la voz o me voy'], 'correcta' => 0],
            ['enunciado' => '¿Qué tono ayuda a calmar la conversación?', 'opciones' => ['Uno más alto que el del cliente', 'Uno pausado y más bajo que el suyo', 'No hablar nada'], 'correcta' => 1],
            ['enunciado' => 'El cliente te insulta. ¿Qué es lo correcto?', 'opciones' => ['Contestarle igual', 'Seguir aguantando hasta que se calme', 'Terminar con educación, irte y avisar a tu coordinador'], 'correcta' => 2],
        ],
    ],

    // ---------------------------------------------------------------- Bienestar
    [
        'codigo' => 'GESTION_ESTRES',
        'titulo' => 'Gestión del estrés en ruta',
        'resumen' => 'Técnicas rápidas para bajar la tensión entre entregas y llegar a casa con energía.',
        'categoria' => 'BIENESTAR', 'nivel' => 'BASICO', 'duracion' => 4,
        'puestos' => ['CONDUCTOR', 'REPARTIDOR', 'MOZO_ALMACEN', 'COORDINADOR'],
        'etiquetas' => ['estrés', 'respiración', 'bienestar', 'tráfico'],
        'contenido' => [
            'objetivos' => [
                'Reconocer cuándo el estrés empieza a subir.',
                'Usar una respiración de un minuto entre entregas.',
                'Separar lo que depende de ti de lo que no.',
            ],
            'bloques' => [
                ['tipo' => 'imagen', 'ilustracion' => 'BIENESTAR', 'alt' => 'Persona respirando con calma junto a la furgoneta'],
                ['tipo' => 'texto', 'titulo' => 'El estrés avisa', 'texto' => 'Mandíbula apretada, hombros subidos, irritarte con cualquier cosa o conducir con más prisa de la necesaria son señales de que el estrés está subiendo. Detectarlo pronto permite pararlo antes de que te agote o te lleve a un error al volante.'],
                ['tipo' => 'lista', 'titulo' => 'Un minuto entre entregas', 'items' => [
                    'Con la furgoneta parada, apoya la espalda y suelta los hombros.',
                    'Inspira por la nariz contando hasta 4.',
                    'Suelta el aire despacio contando hasta 6.',
                    'Repite cinco veces. Alargar la espiración ayuda al cuerpo a relajarse.',
                ]],
                ['tipo' => 'consejo', 'texto' => 'El atasco no depende de ti; cómo lo vives, sí. Avisa del retraso y suelta lo que no puedes cambiar. Si el estrés no baja durante semanas, habla con tu responsable o pide ayuda: no tienes que llevarlo solo.'],
            ],
        ],
        'preguntas' => [
            ['enunciado' => '¿Cuál es una señal de que el estrés está subiendo?', 'opciones' => ['Mandíbula apretada y hombros subidos', 'Tener ganas de comer', 'Terminar la ruta antes de tiempo'], 'correcta' => 0],
            ['enunciado' => 'En la respiración de un minuto, ¿qué parte es más larga?', 'opciones' => ['La inspiración', 'La espiración', 'Las dos iguales'], 'correcta' => 1],
            ['enunciado' => 'Llevas semanas con mucho estrés que no baja. ¿Qué es lo más útil?', 'opciones' => ['Aguantar hasta las vacaciones', 'Trabajar más rápido para acabar antes', 'Hablarlo con tu responsable o pedir ayuda'], 'correcta' => 2],
        ],
    ],
    [
        'codigo' => 'PAUSAS_ACTIVAS',
        'titulo' => 'Pausas activas e higiene postural',
        'resumen' => 'Estiramientos de dos minutos y una buena postura al volante para cuidar cuello, espalda y muñecas.',
        'categoria' => 'BIENESTAR', 'nivel' => 'BASICO', 'duracion' => 3,
        'puestos' => ['CONDUCTOR', 'REPARTIDOR', 'MOZO_ALMACEN', 'ADMINISTRATIVO'],
        'etiquetas' => ['postura', 'estiramientos', 'espalda', 'prevención'],
        'contenido' => [
            'objetivos' => [
                'Ajustar el asiento para conducir sin cargar la espalda.',
                'Hacer tres estiramientos en dos minutos.',
            ],
            'bloques' => [
                ['tipo' => 'imagen', 'ilustracion' => 'BIENESTAR', 'alt' => 'Persona estirando los brazos junto a la furgoneta'],
                ['tipo' => 'texto', 'titulo' => 'Tu asiento, bien ajustado', 'texto' => 'Siéntate con la espalda apoyada del todo en el respaldo. Debes pisar el embrague a fondo con la rodilla ligeramente flexionada y llegar a la parte alta del volante con el codo un poco doblado. El reposacabezas, a la altura de la parte alta de tu cabeza.'],
                ['tipo' => 'lista', 'titulo' => 'Tres estiramientos en dos minutos', 'items' => [
                    'Cuello: inclina la oreja hacia el hombro, 15 segundos por lado, sin forzar.',
                    'Espalda: de pie, manos en la cintura, inclínate un poco hacia atrás y vuelve. Cinco veces.',
                    'Muñecas: brazo estirado, con la otra mano lleva los dedos hacia ti, 15 segundos por mano.',
                ]],
                ['tipo' => 'consejo', 'texto' => 'Aprovecha cada parada larga para bajar y moverte un minuto. El cuerpo agradece más pausas cortas que una larga al final.'],
            ],
        ],
        'preguntas' => [
            ['enunciado' => '¿Cómo debe quedar la rodilla al pisar el embrague a fondo?', 'opciones' => ['Ligeramente flexionada', 'Totalmente estirada', 'Muy doblada, cerca del volante'], 'correcta' => 0],
            ['enunciado' => '¿Qué es mejor para la espalda en una jornada de reparto?', 'opciones' => ['Una sola pausa larga al final', 'Varias pausas cortas para moverse', 'No parar para acabar antes'], 'correcta' => 1],
        ],
    ],
    [
        'codigo' => 'DESCANSO_TURNOS',
        'titulo' => 'Descanso y sueño en turnos rotativos',
        'resumen' => 'Cómo dormir mejor cuando cambias de turno o trabajas de noche.',
        'categoria' => 'BIENESTAR', 'nivel' => 'INTERMEDIO', 'duracion' => 4,
        'puestos' => ['CONDUCTOR', 'MOZO_ALMACEN', 'REPARTIDOR'],
        'etiquetas' => ['sueño', 'turnos', 'noche', 'descanso'],
        'contenido' => [
            'objetivos' => [
                'Preparar el dormitorio para dormir de día.',
                'Planificar el sueño al cambiar de turno.',
                'Usar bien la cafeína y las siestas.',
            ],
            'bloques' => [
                ['tipo' => 'imagen', 'ilustracion' => 'BIENESTAR', 'alt' => 'Dormitorio a oscuras con un reloj que marca la mañana'],
                ['tipo' => 'texto', 'titulo' => 'Dormir de día es posible', 'texto' => 'El cuerpo duerme mejor a oscuras, en silencio y con el dormitorio fresco. Si sales del turno de noche, evita la luz fuerte de camino a casa (gafas de sol ayudan) y acuéstate pronto. Intenta dormir siempre a la misma hora en los días del mismo turno: un adulto necesita entre 7 y 9 horas.'],
                ['tipo' => 'lista', 'titulo' => 'Pequeños cambios que ayudan', 'items' => [
                    'Persianas bajadas o antifaz, y el móvil en silencio.',
                    'Nada de cafeína en las 4 a 6 horas antes de dormir.',
                    'Una siesta de 20 a 30 minutos antes del turno de noche.',
                    'Al rotar, cambia la hora de dormir poco a poco si puedes.',
                ]],
                ['tipo' => 'consejo', 'texto' => 'El sueño es parte de tu seguridad al volante. Si los cambios de turno te impiden descansar, cuéntaselo a tu responsable.'],
            ],
        ],
        'preguntas' => [
            ['enunciado' => '¿Cómo debe estar el dormitorio para dormir de día?', 'opciones' => ['A oscuras, en silencio y fresco', 'Con la persiana subida para despertarse fácil', 'Con la televisión encendida'], 'correcta' => 0],
            ['enunciado' => '¿Cuándo conviene dejar de tomar café antes de dormir?', 'opciones' => ['Media hora antes', 'Entre 4 y 6 horas antes', 'No influye en el sueño'], 'correcta' => 1],
            ['enunciado' => '¿Qué siesta ayuda antes de un turno de noche?', 'opciones' => ['Ninguna, quita el sueño de después', 'Una de tres horas', 'Una de 20 a 30 minutos'], 'correcta' => 2],
        ],
    ],

    // ---------------------------------------------------------------- Desarrollo profesional
    [
        'codigo' => 'COORDINADOR_RUTA',
        'titulo' => 'Primeros pasos como coordinador de ruta',
        'resumen' => 'Organizar la salida, comunicar al equipo y resolver imprevistos sin perder el control del día.',
        'categoria' => 'DESARROLLO', 'nivel' => 'AVANZADO', 'duracion' => 5,
        'puestos' => ['COORDINADOR', 'CONDUCTOR', 'REPARTIDOR'],
        'etiquetas' => ['liderazgo', 'coordinación', 'equipo', 'promoción'],
        'contenido' => [
            'objetivos' => [
                'Preparar la salida del equipo en diez minutos.',
                'Comunicar cambios de forma clara.',
                'Repartir los imprevistos con criterio.',
            ],
            'bloques' => [
                ['tipo' => 'imagen', 'ilustracion' => 'DESARROLLO', 'alt' => 'Coordinador hablando con su equipo junto a varias furgonetas'],
                ['tipo' => 'texto', 'titulo' => 'Tu nuevo trabajo es que el equipo funcione', 'texto' => 'Como coordinador ya no se te mide solo por tus entregas, sino por las del equipo. Empieza el día con una revisión breve: rutas asignadas, vehículos disponibles, bajas y entregas urgentes. Diez minutos de preparación evitan una mañana de llamadas.'],
                ['tipo' => 'lista', 'titulo' => 'Cuando algo cambia', 'items' => [
                    'Avisa a las personas afectadas lo antes posible y por un solo canal.',
                    'Explica qué cambia, por qué y qué esperas de cada uno.',
                    'Reparte los imprevistos de forma equilibrada, no siempre a los mismos.',
                    'Al final del día, agradece en público y corrige en privado.',
                ]],
                ['tipo' => 'consejo', 'texto' => 'Pregunta a tu equipo qué les complica el día. Muchas mejoras salen de quien hace la ruta.'],
            ],
        ],
        'preguntas' => [
            ['enunciado' => '¿Por qué se mide sobre todo a un coordinador?', 'opciones' => ['Por cómo funciona su equipo', 'Por sus propias entregas', 'Por las horas que hace'], 'correcta' => 0],
            ['enunciado' => 'Hay un cambio de ruta urgente. ¿Cómo lo comunicas?', 'opciones' => ['Por varios canales a la vez para asegurar', 'Pronto, por un solo canal, explicando qué cambia y por qué', 'Al final del día, cuando haya calma'], 'correcta' => 1],
            ['enunciado' => '¿Dónde conviene corregir un error de alguien del equipo?', 'opciones' => ['Delante de todos, para que aprendan', 'Por el grupo de mensajería', 'En privado'], 'correcta' => 2],
        ],
    ],
    [
        'codigo' => 'HERRAMIENTAS_DIGITALES',
        'titulo' => 'Herramientas digitales del repartidor (app, escáner, POD)',
        'resumen' => 'La app de reparto, el escáner y la prueba de entrega, y cómo proteger los datos de los clientes.',
        'categoria' => 'DESARROLLO', 'nivel' => 'BASICO', 'duracion' => 4,
        'puestos' => ['REPARTIDOR', 'CONDUCTOR', 'MOZO_ALMACEN'],
        'etiquetas' => ['app', 'escáner', 'POD', 'acogida', 'datos personales'],
        'contenido' => [
            'objetivos' => [
                'Escanear y registrar cada movimiento del paquete.',
                'Cerrar la prueba de entrega (POD) correctamente.',
                'Proteger los datos personales de los clientes.',
            ],
            'bloques' => [
                ['tipo' => 'imagen', 'ilustracion' => 'DESARROLLO', 'alt' => 'Móvil con la app de reparto y un escáner de códigos'],
                ['tipo' => 'texto', 'titulo' => 'Cada escaneo cuenta una parte de la historia', 'texto' => 'El paquete se escanea al cargarlo, al entregarlo y si vuelve al almacén. Si un escaneo falta, para el sistema el paquete "desaparece" y el cliente no sabe dónde está su pedido. La POD, o prueba de entrega, es la firma, la foto o el código que demuestra que el paquete llegó.'],
                ['tipo' => 'lista', 'titulo' => 'Datos de clientes: con cuidado', 'items' => [
                    'Haz fotos solo del paquete y del lugar de entrega, nunca de la persona.',
                    'No compartas direcciones ni teléfonos de clientes por mensajería personal.',
                    'Bloquea el móvil de trabajo con código y no se lo dejes a nadie.',
                    'Si pierdes el escáner o el móvil, avisa de inmediato.',
                ]],
                ['tipo' => 'consejo', 'texto' => 'Si la app falla, anota a mano la hora y el motivo, y regístralo en cuanto vuelva a funcionar.'],
            ],
        ],
        'preguntas' => [
            ['enunciado' => '¿Qué es la POD?', 'opciones' => ['La prueba de que el paquete se entregó', 'El código postal del destino', 'La hoja de ruta del día'], 'correcta' => 0],
            ['enunciado' => '¿Qué foto es correcta como prueba de entrega?', 'opciones' => ['Una foto del cliente con el paquete', 'Una foto del paquete en el lugar de entrega', 'Una foto del DNI del cliente'], 'correcta' => 1],
            ['enunciado' => 'Has perdido el móvil de trabajo. ¿Qué haces?', 'opciones' => ['Espero a ver si aparece', 'Me descargo la app en mi móvil y sigo', 'Aviso a la empresa de inmediato'], 'correcta' => 2],
        ],
    ],
];
