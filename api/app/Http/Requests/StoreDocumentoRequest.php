<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta o renovación de un documento.
 *
 * Un detalle importante: el sistema NO pide ni calcula la fecha legal de
 * vencimiento. Pide la fecha que está IMPRESA en el documento. Si la flota
 * además tiene una regla de frecuencia configurada y confirmada, esa se usa
 * como estimación, pero nunca reemplaza a la fecha real.
 *
 * Por eso aquí no hay ningún campo del tipo "periodicidad legal": esa
 * configuración vive en `configuraciones_alerta` y en
 * `reglas_frecuencia_documento`, y exige quién la confirmó.
 */
class StoreDocumentoRequest extends FormRequest
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
            // Exactamente uno de los dos: el documento es del vehículo o es
            // del conductor. La base también lo exige (restricción
            // `documentos_un_sujeto`).
            'vehiculo_id' => ['required_without:conductor_id', 'nullable', 'string', 'exists:vehiculos,id'],
            'conductor_id' => ['required_without:vehiculo_id', 'nullable', 'string', 'exists:conductores,id'],

            'tipo_documento_id' => ['required', 'string', 'exists:tipos_documento,id'],
            'numero' => ['nullable', 'string', 'max:120'],
            'emisora' => ['nullable', 'string', 'max:160'],
            'organo_emisor' => ['nullable', 'string', 'max:160'],
            'fecha_emision' => ['nullable', 'date'],
            'fecha_vencimiento' => ['nullable', 'date', 'after_or_equal:fecha_emision'],
            'estado' => ['nullable', Rule::in(['vigente', 'anulado', 'historico'])],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'vehiculo_id.required_without' => 'Indique el vehículo o el conductor al que pertenece el documento.',
            'conductor_id.required_without' => 'Indique el vehículo o el conductor al que pertenece el documento.',
            'fecha_vencimiento.after_or_equal' => 'El documento no puede vencer antes de emitirse.',
        ];
    }

    /**
     * Después de validar, se comprueba que no se hayan enviado los dos
     * sujetos. `required_without` cubre el caso de que falte uno, pero no el
     * de que se envíen los dos.
     */
    public function withValidator($validador): void
    {
        $validador->after(function ($validador): void {
            if ($this->filled('vehiculo_id') && $this->filled('conductor_id')) {
                $validador->errors()->add(
                    'vehiculo_id',
                    'Un documento pertenece al vehículo o al conductor, nunca a los dos.'
                );
            }
        });
    }
}