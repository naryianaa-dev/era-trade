<?php
/**
 * Централизованный конфиг цен скандинавского аукциона.
 * Все стоимости ставок берутся отсюда — менять только здесь.
 */

define('BID_PRICES', [

    // ── Уважаемый (бесплатная регистрация) ───────────
    'respected' => [
        'reg_cost'       => 0,
        'cash'           => 2490,   // QR / квитанция
        'balance'        => 1990,   // оплата с баланса ЛК
        'pack_size'      => 20,
        'pack_discount'  => 0.25,   // скидка 25% на пакет
        // pack_per_bid = round(2490 * (1 - 0.25)) = 1867 ₽
        // pack_total   = 1867 * 20 = 37 340 ₽
    ],

    // ── Ответственный (регистрация 8 000 ₽) ──────────
    'responsible' => [
        'reg_cost'       => 8000,
        'cash'           => 1890,   // QR / квитанция
        'balance'        => 1490,   // оплата с баланса ЛК
        'pack_size'      => 20,
        'pack_discount'  => 0.40,   // скидка 40% на пакет
        // pack_per_bid = round(1890 * (1 - 0.40)) = 1134 ₽
        // pack_total   = 1134 * 20 = 22 680 ₽
    ],

]);

// Бонус организатора: % от выручки со ставок (выручка от ставок —
// варьируемая часть выручки оператора; основная часть фиксируется при
// публикации лота как комиссия 5%). 15% от выручки со ставок начисляется
// организатору лота при завершении аукциона.
define('ORGANIZER_BONUS_PCT', 0.15);   // 15%

// Базовая комиссия оператора при публикации лота (от стартовой цены).
// 5% — фиксированная часть выручки оператора независимо от хода торгов.
define('OPERATOR_PUBLISH_FEE_PCT', 0.05);   // 5%

/**
 * Возвращает стоимость одной ставки для пользователя.
 *
 * @param string $user_type      'respected' | 'responsible'
 * @param string $payment_method 'cash' | 'balance' | 'pack'
 * @return int
 */
function getBidCost(string $user_type, string $payment_method): int {
    $cfg = BID_PRICES[$user_type] ?? BID_PRICES['respected'];

    if ($payment_method === 'pack') {
        $per_bid = (int)round($cfg['cash'] * (1 - $cfg['pack_discount']));
        return $per_bid;
    }

    return (int)($cfg[$payment_method] ?? $cfg['cash']);
}

/**
 * Возвращает стоимость пакета из 20 ставок.
 *
 * @param string $user_type 'respected' | 'responsible'
 * @return array ['per_bid' => int, 'total' => int, 'size' => int]
 */
function getPackPrice(string $user_type): array {
    $cfg     = BID_PRICES[$user_type] ?? BID_PRICES['respected'];
    $per_bid = (int)round($cfg['cash'] * (1 - $cfg['pack_discount']));
    return [
        'per_bid' => $per_bid,
        'total'   => $per_bid * $cfg['pack_size'],
        'size'    => $cfg['pack_size'],
    ];
}

/**
 * Рассчитывает бонус организатора.
 *
 * @param int $total_bids   количество принятых ставок
 * @param int $bid_cost     стоимость одной ставки (базовая, без скидок)
 * @return int
 */
function getOrganizerBonus(int $total_bids, int $bid_cost): int {
    return (int)round($total_bids * $bid_cost * ORGANIZER_BONUS_PCT);
}
