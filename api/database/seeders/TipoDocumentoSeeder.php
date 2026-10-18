<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\TipoDocumento;
use Illuminate\Database\Seeder;

/**
 * Catálogo de tipos de documento.
 *
 * LO QUE ESTÁ Y LO QUE NO ESTÁ AQUÍ
 * ---------------------------------
 * Está el NOMBRE de los documentos que una flota chilena normalmente maneja.
 * No está ningún plazo legal.
 *
 * Las columnas `plazo_legal_dias`, `plazo_legal_fuente`,
 * `plazo_legal_confirmado_por` y `plazo_legal_confirmado_at` quedan en NULL a
 * propósito. En el entorno donde se escribió este código no se verificó la
 * normativa chilena aplicable, y escribir un plazo legal de memoria es
 * exactamente el riesgo que este producto vino a eliminar: un operador que
 * confía en un número inventado deja de mirar el documento físico.
 *
 * CUÁNDO SE LLENAN
 * ----------------
 * Hefesto confirma los plazos con un profesional del transporte o con el
 * cliente, y los carga con su fuente. Hasta entonces, el sistema funciona
 * igual: la fecha de vencimiento que se usa es la que viene IMPRESA en cada
 * documento, que es el dato real y no necesita ser deducido.
 *
 * Lo mismo aplica a las reglas de frecuencia (`reglas_frecuencia_documento`):
 * hay un mecanismo entero para que una periodicidad no entre en el cálculo de
 * avisos hasta que quede registrado quién la confirmó.
 */
class TipoDocumentoSeeder extends Seeder
{
    public function run(): void
    {
        $tipos = [
            [
                'clave' => 'permiso_circulacion',
                'nombre' => 'Permiso de circulación',
                'descripcion' => 'Autorización Municipal para circular. Se porta en el vehículo.',
                'sujeto' => 'vehiculo',
                'requiere_vencimiento' => true,
                'organo_emisor' => 'Municipalidad',
                'nota' => 'Plazo legal pendiente de confirmación. Cargar la fecha que figura en el documento.',
            ],
            [
                'clave' => 'seguro_obligatorio',
                'nombre' => 'Seguro obligatorio',
                'descripcion' => 'Seguro exigido para circular. Su vigencia es responsabilidad de la persona que conduce.',
                'sujeto' => 'vehiculo',
                'requiere_vencimiento' => true,
                'organo_emisor' => 'Compañía aseguradora',
                'nota' => 'Plazo legal pendiente de confirmación. Cargar la fecha que figura en la póliza.',
            ],
            [
                'clave' => 'revision_tecnica',
                'nombre' => 'Revisión técnica',
                'descripcion' => 'Inspección vehicular obligatoria.',
                'sujeto' => 'vehiculo',
                'requiere_vencimiento' => true,
                'organo_emisor' => 'Planta de inspección técnica autorizada',
                'nota' => 'Plazo legal pendiente de confirmación. La renovación se produce por inspección: '
                    .'cargar la fecha del documento y, si se quiere, configurar una regla de frecuencia CONFIRMADA.',
            ],
            [
                'clave' => 'padron_fiscalizacion',
                'nombre' => 'Padrón de fiscalización',
                'descripcion' => 'Inscripción en el registro correspondiente, exigida según el tipo de servicio.',
                'sujeto' => 'vehiculo',
                'requiere_vencimiento' => true,
                'organo_emisor' => null,
                'nota' => 'Aplica según el tipo de servicio del vehículo. '
                    .'La exigencia concreta debe confirmarla el cliente: el sistema no deduce a qué flota aplica.',
            ],
            [
                'clave' => 'licencia_conducir',
                'nombre' => 'Licencia de conducir',
                'descripcion' => 'Documento del conductor, no del vehículo.',
                'sujeto' => 'conductor',
                'requiere_vencimiento' => true,
                'organo_emisor' => 'Servicio de registro',
                'nota' => 'Plazo legal pendiente de confirmación. Cargar la fecha que figura en la licencia.',
            ],
            [
                'clave' => 'certificado_emisiones',
                'nombre' => 'Certificado de emisiones',
                'descripcion' => 'Documento ambiental del vehículo.',
                'sujeto' => 'vehiculo',
                'requiere_vencimiento' => false,
                'organo_emisor' => null,
                'nota' => 'No todos los vehículos lo requieren. Dejar sin fecha si no aplica.',
            ],
            [
                'clave' => 'certificado_carga',
                'nombre' => 'Certificado de habilitación para carga',
                'descripcion' => 'Habilitación exigida según el tipo de servicio.',
                'sujeto' => 'vehiculo',
                'requiere_vencimiento' => true,
                'organo_emisor' => null,
                'nota' => 'La exigencia depende del tipo de servicio y debe confirmarla el cliente.',
            ],
        ];

        foreach ($tipos as $tipo) {
            TipoDocumento::updateOrCreate(
                ['clave' => $tipo['clave']],
                $tipo + [
                    'plazo_legal_dias' => null,
                    'plazo_legal_fuente' => null,
                    'plazo_legal_confirmado_por' => null,
                    'plazo_legal_confirmado_at' => null,
                    'activo' => true,
                ]
            );
        }
    }
}