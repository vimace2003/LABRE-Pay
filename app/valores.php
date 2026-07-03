<?php
/**
 * Regras de valor: vencimento anual, pro-rata de adesão/readmissão e
 * "valor devido na data" em três fases (desconto → cheio → multa/juros).
 */

/** Data de vencimento da anuidade de um ano de referência (padrão 31/01, Art. 27). */
function vencimento_do_ano(int $ano): string
{
    $dia = (int)setting('venc_dia', '31');
    $mes = (int)setting('venc_mes', '1');
    $dia = min($dia, (int)date('t', mktime(0, 0, 0, $mes, 1, $ano)));
    return sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
}

/** Próximo vencimento a partir de uma data (para o cálculo pro-rata). */
function proximo_vencimento(string $aPartirDe): string
{
    $ano = (int)date('Y', strtotime($aPartirDe));
    $venc = vencimento_do_ano($ano);
    if ($venc <= $aPartirDe) {
        $venc = vencimento_do_ano($ano + 1);
    }
    return $venc;
}

/**
 * Vencimento adequado para uma cobrança do ano X emitida hoje:
 * antes do vencimento estatutário → o próprio (ex.: 31/01);
 * com o ciclo já em andamento → N meses após a emissão (padrão 3, configurável).
 */
function vencimento_para_emissao(int $ano, ?string $hoje = null): string
{
    $hoje = $hoje ?: date('Y-m-d');
    $vencAno = vencimento_do_ano($ano);
    if ($hoje <= $vencAno) return $vencAno;
    $meses = max(1, (int)setting('prazo_venc_meses', '3'));
    return date('Y-m-d', strtotime($hoje . ' +' . $meses . ' months'));
}

/**
 * Pro-rata: meses (arredondados para cima) entre a adesão e o próximo
 * vencimento × (anuidade/12). Retorna ['meses', 'valor', 'vencimento', 'ano'].
 */
function calcular_prorata(string $dataAdesao): array
{
    $anuidade = (float)setting('anuidade_valor', '0');
    $venc = proximo_vencimento($dataAdesao);
    $ini = new DateTime($dataAdesao);
    $fim = new DateTime($venc);
    $diff = $ini->diff($fim);
    $meses = $diff->y * 12 + $diff->m + ($diff->d > 0 ? 1 : 0);
    $meses = max(1, min(12, $meses));
    $valor = round($anuidade / 12 * $meses, 2);
    return [
        'meses' => $meses,
        'valor' => $valor,
        'vencimento' => $venc,
        // A proporcional pertence ao CICLO em que a adesão aconteceu (quem entra
        // em jul/2026 paga o restante do ciclo 2026); no ano seguinte entra no
        // lote cheio normalmente. O ciclo é o ano anterior ao próximo vencimento.
        'ano' => (int)date('Y', strtotime($venc)) - 1,
    ];
}

/** Data-limite do desconto por antecipação para uma cobrança (ou null se inativo). */
function desconto_data_limite(array $charge): ?string
{
    if (setting('desconto_ativo', '0') !== '1') return null;
    $dia = (int)setting('desconto_dia', '31');
    $mes = (int)setting('desconto_mes', '12');
    $anoVenc = (int)date('Y', strtotime($charge['vencimento']));
    $dia = min($dia, (int)date('t', mktime(0, 0, 0, $mes, 1, $anoVenc)));
    $limite = sprintf('%04d-%02d-%02d', $anoVenc, $mes, $dia);
    if ($limite >= $charge['vencimento']) {
        $dia = min((int)setting('desconto_dia', '31'), (int)date('t', mktime(0, 0, 0, $mes, 1, $anoVenc - 1)));
        $limite = sprintf('%04d-%02d-%02d', $anoVenc - 1, $mes, $dia);
    }
    return $limite;
}

/**
 * Valor devido de uma cobrança em uma data.
 * Retorna ['valor','fase','desconto','acrescimos','fase_expira'] onde fase é
 * 'desconto' | 'normal' | 'atraso' e fase_expira é a data (Y-m-d) em que o
 * valor muda (null se não muda mais).
 */
function valor_devido(array $charge, ?string $data = null): array
{
    $data = $data ?: date('Y-m-d');
    $base = (float)$charge['valor'];
    $venc = $charge['vencimento'];
    $isenta = !empty($charge['isenta_multa']);

    // Fase 1: desconto por antecipação (não se aplica a pro-rata/isentas,
    // que já têm valor próprio definido na adesão)
    if (!$isenta) {
        $limite = desconto_data_limite($charge);
        if ($limite !== null && $data <= $limite) {
            $tipo = setting('desconto_tipo', 'percent');
            $dv = (float)setting('desconto_valor', '0');
            $desc = $tipo === 'percent' ? round($base * $dv / 100, 2) : min($dv, $base);
            return ['valor' => round($base - $desc, 2), 'fase' => 'desconto', 'desconto' => $desc, 'acrescimos' => 0.0, 'fase_expira' => $limite];
        }
    }

    // Fase 2: valor cheio até o vencimento
    if ($data <= $venc || $isenta) {
        return ['valor' => $base, 'fase' => 'normal', 'desconto' => 0.0, 'acrescimos' => 0.0, 'fase_expira' => $isenta ? null : $venc];
    }

    // Fase 3: atraso — multa fixa + juros de mora proporcionais por dia
    $multaPct = (float)setting('multa_percent', '0');
    $jurosMesPct = (float)setting('juros_mes_percent', '0');
    $dias = (int)((strtotime($data) - strtotime($venc)) / 86400);
    $multa = round($base * $multaPct / 100, 2);
    $juros = round($base * $jurosMesPct / 100 * $dias / 30, 2);
    $acr = round($multa + $juros, 2);
    // Com juros diários o valor muda todo dia; expira o link no fim do dia corrente.
    return ['valor' => round($base + $acr, 2), 'fase' => 'atraso', 'desconto' => 0.0, 'acrescimos' => $acr, 'fase_expira' => $jurosMesPct > 0 ? $data : null];
}
