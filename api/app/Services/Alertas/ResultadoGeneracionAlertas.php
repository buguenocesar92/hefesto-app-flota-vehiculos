<?php

declare(strict_types=1);

namespace App\Services\Alertas;

/**
 * Resultado de una ejecución del generador de alertas.
 *
 * Existe por una razón concreta: hacer el resultado OBSERVABLE. La forma de
 * demostrar que la tarea programada no duplica avisos es que en la segunda
 * corrida `alertasGeneradas` sea cero y `alertasOmitidasPorDuplicado` sea
 * igual al número de alertas de la primera. Sin este objeto, la única forma de
 * comprobarlo sería contar filas, y el comando no podría contarlas solo.
 *
 * Los contadores también son lo que se muestra en el tablero: "última
 * ejecución: hace 6 horas, 4 avisos nuevos, 31 ya existían".
 */
final class ResultadoGeneracionAlertas
{
    /** Documentos vigentes revisados en esta ejecución. */
    public int $documentosRevisados = 0;

    /** Documentos que no tienen fecha de vencimiento ni regla aplicable. */
    public int $documentosSinFecha = 0;

    /** Documentos que ya estaban vencidos al momento de la ejecución. */
    public int $documentosVencidos = 0;

    /** Avisos efectivamente insertados. */
    public int $alertasGeneradas = 0;

    /**
     * Avisos que ya existían para ese documento y ese periodo, y por lo tanto
     * no se duplicaron. En una primera ejecución sobre datos limpios vale 0.
     */
    public int $alertasOmitidasPorDuplicado = 0;

    /** Avisos enviados a la cola para su entrega. */
    public int $alertasDespachadas = 0;

    public function sumar(self $otro): void
    {
        $this->documentosRevisados += $otro->documentosRevisados;
        $this->documentosSinFecha += $otro->documentosSinFecha;
        $this->documentosVencidos += $otro->documentosVencidos;
        $this->alertasGeneradas += $otro->alertasGeneradas;
        $this->alertasOmitidasPorDuplicado += $otro->alertasOmitidasPorDuplicado;
        $this->alertasDespachadas += $otro->alertasDespachadas;
    }

    /**
     * @return array<string, int>
     */
    public function aArreglo(): array
    {
        return [
            'documentos_revisados' => $this->documentosRevisados,
            'documentos_sin_fecha' => $this->documentosSinFecha,
            'documentos_vencidos' => $this->documentosVencidos,
            'alertas_generadas' => $this->alertasGeneradas,
            'alertas_omitidas_por_duplicado' => $this->alertasOmitidasPorDuplicado,
            'alertas_despachadas' => $this->alertasDespachadas,
        ];
    }
}