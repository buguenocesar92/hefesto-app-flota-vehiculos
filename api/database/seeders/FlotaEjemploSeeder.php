<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Asignacion;
use App\Models\CentroCosto;
use App\Models\ConfiguracionAlerta;
use App\Models\Conductor;
use App\Models\Mantencion;
use App\Models\Tenant;
use App\Models\TipoDocumento;
use App\Models\TipoServicio;
use App\Models\Vehiculo;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Flota de ejemplo.
 *
 * Datos pensados para PROBAR el sistema en desarrollo, no para mostrar a un
 * cliente:
 *
 * 1. Las fechas de vencimiento están repartidas a propósito en todas las
 *    ventanas: hay documentos ya vencidos, hay que vencen esta semana, hay que
 *    vencen en un mes, hay que vencen en seis meses. Con eso se puede
 *    comprobar que cada umbral genera su aviso, y que un documento ya
 *    vencido genera el aviso de vencido y no el de 30 días.
 *
 * 2. Un vehículo está EN MANTENCIÓN y con la revisión técnica VENCIDA al
 *    mismo tiempo. Es el caso que el dueño de la flota señaló: si el tablero
 *    muestra solo "en mantención", el segundo hecho desaparece.
 *
 * 3. El kilometraje viene de tres fuentes distintas (odómetro, taller y
 *    carga de combustible), para que se vea que el sistema sabe de dónde
 *    salió cada número.
 *
 * 4. Una regla de frecuencia existe pero está SIN CONFIRMAR. El sistema la
 *    ignora para calcular alertas, y por eso ese documento se avisa por su
 *    fecha impresa y no por la periodicidad inventada.
 */
class FlotaEjemploSeeder extends Seeder
{
    public function run(): void
    {
        $hoy = Carbon::now()->startOfDay();

        $tenant = Tenant::updateOrCreate(
            ['slug' => 'flota-demo'],
            [
                'nombre' => 'Flota Demo',
                'razon_social' => 'Transportes Demo SpA',
                'rut' => '76.543.210-9',
                'correo_contacto' => 'operaciones@flota-demo.example',
                'activo' => true,
                'zona_horaria' => 'America/Santiago',
            ]
        );

        // Los catálogos y las reglas de alerta se crean DENTRO de la
        // transacción con el tenant fijado, porque la RLS no permite escribir
        // sin contexto.
        DB::transaction(function () use ($tenant, $hoy): void {
            $contexto = app(\App\Services\Tenancy\ContextoTenant::class);
            $contexto->establecer($tenant->id);

            $this->catalogo($tenant->id);
            $this->configuracionDeAlertas($tenant->id);

            $conductores = $this->conductores($tenant->id);
            $vehiculos = $this->vehiculos($tenant->id);

            $this->documentos($tenant->id, $vehiculos, $conductores, $hoy);
            $this->lecturasYMantencion($tenant->id, $vehiculos, $hoy);
            $this->asignaciones($vehiculos, $conductores, $hoy);
        });
    }

    /** @param array<string, \App\Models\TipoDocumento> $tipos */
    private function catalogo(string $tenantId): void
    {
        TipoServicio::firstOrCreate(
            ['tenant_id' => $tenantId, 'codigo' => 'CARGA_URBANA'],
            ['nombre' => 'Carga urbana', 'requiere_habilitacion' => false, 'activo' => true]
        );

        TipoServicio::firstOrCreate(
            ['tenant_id' => $tenantId, 'codigo' => 'PASAJEROS'],
            ['nombre' => 'Transporte de pasajeros', 'requiere_habilitacion' => true, 'activo' => true]
        );

        CentroCosto::firstOrCreate(
            ['tenant_id' => $tenantId, 'codigo' => 'CC-100'],
            ['nombre' => 'Operación Santiago', 'activo' => true]
        );

        CentroCosto::firstOrCreate(
            ['tenant_id' => $tenantId, 'codigo' => 'CC-200'],
            ['nombre' => 'Operación Regiones', 'activo' => true]
        );
    }

    private function configuracionDeAlertas(string $tenantId): void
    {
        // Plazos de AVISO, no legales. Son una decisión de operación: cuánto
        // tiempo necesita el cliente para conseguir el documento renovado.
        $umbrales = [
            ['umbral_dias' => 60, 'dias_insistencia_vencido' => 7],
            ['umbral_dias' => 30, 'dias_insistencia_vencido' => 3],
            ['umbral_dias' => 15, 'dias_insistencia_vencido' => 0],
            ['umbral_dias' => 7, 'dias_insistencia_vencido' => 0],
            ['umbral_dias' => 1, 'dias_insistencia_vencido' => 0],
        ];

        foreach ($umbrales as $umbral) {
            ConfiguracionAlerta::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'tipo_documento_id' => null,
                    'umbral_dias' => $umbral['umbral_dias'],
                ],
                [
                    'dias_insistencia_vencido' => $umbral['dias_insistencia_vencido'],
                    'canal' => 'correo',
                    'activa' => true,
                ]
            );
        }
    }

    /**
     * @return array<string, Conductor>
     */
    private function conductores(string $tenantId): array
    {
        $datos = [
            'carla' => ['nombre' => 'Carla', 'apellido_paterno' => 'Muñoz', 'apellido_materno' => 'Pérez', 'clase_licencia' => 'D'],
            'jorge' => ['nombre' => 'Jorge', 'apellido_paterno' => 'Soto', 'apellido_materno' => 'Reyes', 'clase_licencia' => 'B'],
            'maria' => ['nombre' => 'María', 'apellido_paterno' => 'González', 'apellido_materno' => 'Díaz', 'clase_licencia' => 'A1'],
        ];

        $conductores = [];

        foreach ($datos as $clave => $datosConductor) {
            $conductores[$clave] = Conductor::firstOrCreate(
                ['tenant_id' => $tenantId, 'nombre' => $datosConductor['nombre']],
                $datosConductor + ['activo' => true]
            );
        }

        return $conductores;
    }

    /**
     * @return array<string, Vehiculo>
     */
    private function vehiculos(string $tenantId): array
    {
        $tipoCarga = TipoServicio::where('tenant_id', $tenantId)->where('codigo', 'CARGA_URBANA')->first();
        $tipoPasajeros = TipoServicio::where('tenant_id', $tenantId)->where('codigo', 'PASAJEROS')->first();
        $centroSantiago = CentroCosto::where('tenant_id', $tenantId)->where('codigo', 'CC-100')->first();

        $datos = [
            'camion01' => ['patente' => 'ABCD-12', 'marca' => 'Mercedes-Benz', 'modelo' => 'Acco 815', 'anio' => 2021, 'tipo' => $tipoCarga, 'km' => 148_500],
            'camion02' => ['patente' => 'EFGH-34', 'marca' => 'Volvo', 'modelo' => 'VM270', 'anio' => 2022, 'tipo' => $tipoCarga, 'km' => 96_200],
            'camioneta01' => ['patente' => 'IJKL-56', 'marca' => 'Toyota', 'modelo' => 'Hilux', 'anio' => 2020, 'tipo' => $tipoCarga, 'km' => 212_800],
            'furgon01' => ['patente' => 'MNOP-78', 'marca' => 'Peugeot', 'modelo' => 'Expert', 'anio' => 2019, 'tipo' => $tipoCarga, 'km' => 178_300],
            'bus01' => ['patente' => 'QRST-90', 'marca' => 'Mercedes-Benz', 'modelo' => 'Sprinter', 'anio' => 2023, 'tipo' => $tipoPasajeros, 'km' => 41_000],
            'bus02' => ['patente' => 'UVWX-23', 'marca' => 'Ford', 'modelo' => 'Transit', 'anio' => 2018, 'tipo' => $tipoPasajeros, 'km' => 265_100],
        ];

        $vehiculos = [];

        foreach ($datos as $clave => $vehiculo) {
            $vehiculos[$clave] = Vehiculo::updateOrCreate(
                ['tenant_id' => $tenantId, 'patente' => $vehiculo['patente']],
                [
                    'marca' => $vehiculo['marca'],
                    'modelo' => $vehiculo['modelo'],
                    'anio' => $vehiculo['anio'],
                    'tipo_servicio_id' => $vehiculo['tipo']?->id,
                    'centro_costo_id' => $centroSantiago?->id,
                    'kilometraje_referencia' => $vehiculo['km'],
                    'activo' => true,
                ]
            );
        }

        return $vehiculos;
    }

    /**
     * @param  array<string, Vehiculo>  $vehiculos
     * @param  array<string, Conductor>  $conductores
     */
    private function documentos(string $tenantId, array $vehiculos, array $conductores, Carbon $hoy): void
    {
        $tipo = fn (string $clave) => TipoDocumento::where('clave', $clave)->first();

        // CASO 1: vencido hace 5 días. Debe generar el aviso de "vencido" y el
        // de insistencia de 3 días, y NO los de 30, 15 ni 7 (ya pasaron).
        $this->documento(
            tenantId: $tenantId,
            vehiculo: $vehiculos['camion01'],
            tipo: $tipo('permiso_circulacion'),
            numero: 'PC-2025-0001',
            emision: $hoy->copy()->subYears(2)->startOfYear(),
            vencimiento: $hoy->copy()->subDays(5),
        );

        // CASO 2: vence en 3 días. Entra en el umbral de 7 días, y todavía
        // no en los más cortos: al de 1 le faltan tres días, y a los de 15 y 30
        // falta más de un mes.
        $this->documento(
            tenantId: $tenantId,
            vehiculo: $vehiculos['camioneta01'],
            tipo: $tipo('seguro_obligatorio'),
            numero: 'POL-889231',
            emision: $hoy->copy()->subYear(),
            vencimiento: $hoy->copy()->addDays(3),
        );

        // CASO 3: vence HOY. Tiene que salir el aviso con 0 días restantes.
        $this->documento(
            tenantId: $tenantId,
            vehiculo: $vehiculos['furgon01'],
            tipo: $tipo('seguro_obligatorio'),
            numero: 'POL-771204',
            emision: $hoy->copy()->subYear(),
            vencimiento: $hoy->copy(),
        );

        // CASO 4: vence en 25 días. Entra en el umbral de 30, no en los más
        // cortos.
        $this->documento(
            tenantId: $tenantId,
            vehiculo: $vehiculos['bus01'],
            tipo: $tipo('revision_tecnica'),
            numero: 'RT-2026-4412',
            emision: $hoy->copy()->subMonths(14),
            vencimiento: $hoy->copy()->addDays(25),
        );

        // CASO 5: vence en 120 días. No entra en ninguna ventana: sirve para
        // comprobar que el sistema NO avisa lo que todavía no urge.
        $this->documento(
            tenantId: $tenantId,
            vehiculo: $vehiculos['bus02'],
            tipo: $tipo('permiso_circulacion'),
            numero: 'PC-2026-0009',
            emision: $hoy->copy()->subMonths(4),
            vencimiento: $hoy->copy()->addDays(120),
        );

        // CASO 6: el vehículo que está EN MANTENCIÓN y con el seguro
        // VENCIDO. Es el caso que el dueño de la flota señaló: si la pantalla
        // solo muestra "en mantención", este vencimiento desaparece.
        $this->documento(
            tenantId: $tenantId,
            vehiculo: $vehiculos['bus02'],
            tipo: $tipo('seguro_obligatorio'),
            numero: 'POL-550019',
            emision: $hoy->copy()->subYears(3),
            vencimiento: $hoy->copy()->subDays(21),
        );

        // CASO 7: licencia de conductor, con fecha impresa. Documento del
        // conductor, no del vehículo: comparte la tabla de documentos.
        $this->documento(
            tenantId: $tenantId,
            vehiculo: null,
            conductor: $conductores['carla'],
            tipo: $tipo('licencia_conducir'),
            numero: 'LC-CARLA-001',
            emision: $hoy->copy()->subYears(5),
            vencimiento: $hoy->copy()->addDays(12),
        );

        $this->documento(
            tenantId: $tenantId,
            vehiculo: null,
            conductor: $conductores['jorge'],
            tipo: $tipo('licencia_conducir'),
            numero: 'LC-JORGE-002',
            emision: $hoy->copy()->subYears(3),
            vencimiento: $hoy->copy()->subDays(40),
        );

        // CASO 8: documento SIN fecha de vencimiento y SIN regla de
        // frecuencia. El sistema lo cuenta como "sin fecha" y NO avisa: no
        // puede inventar un plazo. Aparece en el panel como dato incompleto.
        $this->documento(
            tenantId: $tenantId,
            vehiculo: $vehiculos['camion02'],
            tipo: $tipo('certificado_emisiones'),
            numero: 'CE-3321',
            emision: $hoy->copy()->subYear(),
            vencimiento: null,
        );
    }

    private function documento(
        string $tenantId,
        ?Vehiculo $vehiculo,
        $tipo,
        string $numero,
        Carbon $emision,
        ?Carbon $vencimiento,
        ?Conductor $conductor = null,
    ): void {
        \App\Models\Documento::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'tipo_documento_id' => $tipo?->id,
                'numero' => $numero,
            ],
            [
                'vehiculo_id' => $vehiculo?->id,
                'conductor_id' => $conductor?->id,
                'fecha_emision' => $emision->toDateString(),
                'fecha_vencimiento' => $vencimiento?->toDateString(),
                'estado' => 'vigente',
            ]
        );
    }

    /**
     * Kilometraje desde tres fuentes distintas y una mantención en curso.
     *
     * @param  array<string, Vehiculo>  $vehiculos
     */
    private function lecturasYMantencion(string $tenantId, array $vehiculos, Carbon $hoy): void
    {
        $registro = app(\App\Services\Kilometraje\RegistroDeKilometraje::class);
        $usuarioId = \App\Models\Usuario::where('tenant_id', $tenantId)->value('id');

        // 1. Lectura de odómetro, la más común.
        $registro->registrar(
            vehiculo: $vehiculos['camion01'],
            km: 148_500,
            fuente: \App\Enums\FuenteKilometraje::Odometro,
            fecha: $hoy->copy()->subDays(40)->toDateString(),
            usuarioId: $usuarioId,
        );

        // 2. Kilometraje informado por el taller al entregar el vehículo.
        $mantencion = Mantencion::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'vehiculo_id' => $vehiculos['camioneta01']->id,
                'descripcion' => 'Cambio de aceite y filtros',
            ],
            [
                'tipo' => 'kilometraje',
                'estado' => 'completada',
                'km_programado' => 212_000,
                'fecha_programada' => $hoy->copy()->subDays(20)->toDateString(),
                'fecha_ejecucion' => $hoy->copy()->subDays(18)->toDateString(),
                'km_al_entregar' => 212_800,
                'costo' => 185_000,
            ]
        );

        $registro->registrarDesdeMantencion(
            vehiculo: $vehiculos['camioneta01'],
            mantencion: $mantencion,
            km: 212_800,
            usuarioId: $usuarioId,
        );

        // 3. El bus 02 está EN MANTENCIÓN, y además tiene el seguro vencido.
        // Los dos hechos existen a la vez y el tablero tiene que mostrar los
        // dos.
        Mantencion::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'vehiculo_id' => $vehiculos['bus02']->id,
                'descripcion' => 'Reparación de sistema de frenos',
            ],
            [
                'tipo' => 'mixto',
                'estado' => 'en_proceso',
                'km_programado' => 265_000,
                'fecha_programada' => $hoy->copy()->toDateString(),
                'observaciones' => 'El seguro está vencido: hay que resolverlo antes de volver a salir.',
            ]
        );

        // 4. Lectura de odómetro asociada a una carga de combustible. El
        // sistema también genera su propia lectura con esa fuente, pero aquí
        // se registra la lectura de referencia del día.
        \App\Models\CargaCombustible::firstOrCreate(
            ['tenant_id' => $tenantId, 'vehiculo_id' => $vehiculos['camion02']->id, 'numero_boleta' => 'B-1001'],
            [
                'fecha' => $hoy->copy()->subDays(10),
                'litros' => 180,
                'precio_unitario' => 1_050,
                'total' => 189_000,
                'tipo_combustible' => 'diesel',
                'estacion' => 'Estación de servicio centro',
                'km_lectura' => 96_000,
            ]
        );

        \App\Models\CargaCombustible::firstOrCreate(
            ['tenant_id' => $tenantId, 'vehiculo_id' => $vehiculos['camion02']->id, 'numero_boleta' => 'B-1002'],
            [
                'fecha' => $hoy->copy()->subDays(2),
                'litros' => 150,
                'precio_unitario' => 1_090,
                'total' => 163_500,
                'tipo_combustible' => 'diesel',
                'estacion' => 'Estación de servicio centro',
                'km_lectura' => 96_200,
            ]
        );

        // Con estas dos cargas se puede calcular el rendimiento: 200 km con
        // 150 litros. Si el sistema lo guardara como campo, dejaría de ser
        // cierto en cuanto se cargara combustible otra vez.
    }

    /**
     * @param  array<string, Vehiculo>  $vehiculos
     * @param  array<string, Conductor>  $conductores
     */
    private function asignaciones(array $vehiculos, array $conductores, Carbon $hoy): void
    {
        Asignacion::firstOrCreate(
            [
                'vehiculo_id' => $vehiculos['camioneta01']->id,
                'conductor_id' => $conductores['jorge']->id,
                'desde' => $hoy->copy()->subMonths(3)->toDateString(),
            ],
            ['motivo' => 'Conducción permanente']
        );

        // Sin `hasta`: es una asignación abierta. La base impide que el mismo
        // vehículo tenga dos asignaciones abiertas, y que el mismo conductor
        // esté en dos vehículos al mismo tiempo.
    }
}