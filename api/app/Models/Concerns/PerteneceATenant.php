<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Services\Tenancy\ContextoTenant;

/**
 * Marca un modelo como perteneciente a un tenant.
 *
 * El aislamiento real lo hace PostgreSQL (Row Level Security, migracion
 * 2026_03_01_000014). Este trait es la segunda capa, y aporta tres cosas que
 * la RLS sola no da:
 *
 * 1. Que `create()` complete el `tenant_id` desde el contexto. Sin esto,
 *    alguien tendria que acordarse de pasarlo a mano en cada alta, y el
 *    olvido se paga con un error 500 de `WITH CHECK`.
 * 2. Que el modelo sepa si el registro que tiene en memoria es de SU flota.
 *    Es lo que permite que una Policy pueda decir "no es tuyo" sin tener que
 *    volver a consultar.
 * 3. Un metodo de diagnostico (`esDelTenantActual()`) que las pruebas usan
 *    para comprobar el aislamiento desde el lado de la aplicacion.
 */
trait PerteneceATenant
{
    /**
     * Complete el tenant al crear el registro si no viene informado.
     *
     * La firma del evento `creating` de Eloquent entrega el modelo ya
     * instanciado, asi que se puede completar el atributo antes de que se
     * inserte.
     */
    public static function bootPerteneceATenant(): void
    {
        static::creating(static function ($modelo): void {
            if ($modelo->tenant_id !== null) {
                return;
            }

            $tenantId = app(ContextoTenant::class)->tenantIdSolicitado();

            if ($tenantId === null) {
                // Falla aqui y no en la base, y con un mensaje que dice que
                // hacer. Dejar que la base lo rechace con un error de
                // `WITH CHECK` produce un 500 que no ayuda a nadie.
                throw new \RuntimeException(
                    'No se puede crear un registro sin tenant_id: la operacion no tiene contexto de tenant. '
                    .'Ejecute la escritura dentro de una peticion autenticada o de '
                    .'App\Services\Tenancy\ContextoTenant::ejecutarAislado().'
                );
            }

            $modelo->tenant_id = $tenantId;
        });
    }

    /** El registro pertenece a la flota del contexto actual. */
    public function esDelTenantActual(): bool
    {
        $tenantId = app(ContextoTenant::class)->tenantIdSolicitado();

        return $tenantId !== null && $this->tenant_id === $tenantId;
    }

    /**
     * Filtro de alcance para consultas.
     *
     * OJO: este filtro NO es una medida de seguridad. Es una comodidad que
     * evita preguntas tontas. Si el `tenant_id` no coincide, la RLS ya habria
     * devuelto cero filas; el atributo solo evita el trabajo de la consulta.
     * Por eso se documenta como lo que es: si alguien lo borra, el sistema
     * sigue siendo seguro, y si alguien lo cambia por algo que solo filtre
     * "por las dudas", deja de serlo sin avisar.
     */
    public function scopeDelTenantActual($consulta)
    {
        $tenantId = app(ContextoTenant::class)->tenantIdSolicitado();

        if ($tenantId !== null) {
            $consulta->where($this->qualifyColumn('tenant_id'), $tenantId);
        }

        return $consulta;
    }
}