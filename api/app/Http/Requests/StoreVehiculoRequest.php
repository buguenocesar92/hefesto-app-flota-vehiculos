<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\NormalizadorPatente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de vehículo.
 *
 * La patente se normaliza ANTES de validar. Si no, "AB CD 12" y "ABCD12"
 * serían dos vehículos distintos, y la alerta de vencimiento se mandaría
 * dos veces.
 */
class StoreVehiculoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->puedeOperar() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'numero_interno' => ['nullable', 'string', 'max:32'],
            'patente' => ['required', 'string', 'max:16'],
            'vin' => ['nullable', 'string', 'max:17'],
            'marca' => ['required', 'string', 'max:80'],
            'modelo' => ['required', 'string', 'max:80'],
            'anio' => ['required', 'integer', 'min:1950', 'max:'.((int) date('Y') + 2)],
            'color' => ['nullable', 'string', 'max:40'],
            'tipo_servicio_id' => ['required', Rule::exists('tipos_servicio', 'id')],
            'centro_costo_id' => ['nullable', Rule::exists('centros_costo', 'id')],
            'kilometraje_referencia' => ['nullable', 'integer', 'min:0'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'patente.required' => 'La patente es obligatoria.',
            'anio.max' => 'El año no puede ser posterior a '.((int) date('Y') + 2).'.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'patente' => $this->patente !== null
                ? NormalizadorPatente::intentarNormalizar((string) $this->patente)
                : null,
            'vin' => $this->vin !== null
                ? NormalizadorPatente::normalizarVin((string) $this->vin)
                : null,
        ]);
    }
}