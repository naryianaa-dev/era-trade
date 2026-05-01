<?php
/**
 * Финансовый движок ERA ETP
 * Подключать: require_once 'finances.php';
 */

if (!function_exists('getCommissionRate')) {

    /**
     * Получает ставку комиссии площадки для лота/организатора.
     * Приоритет: лот → организатор → глобальная (с учётом $auction_type).
     *
     * $auction_type:
     *   - null        : обычный аукцион/RFP/RFQ и т.п. — дефолт 5%
     *   - 'commission': комиссионная продажа (torgi_list.php) — дефолт 3%
     */
    function getCommissionRate(PDO $pdo, int $lot_id = 0, int $user_id = 0, ?string $auction_type = null): float {
        $default_by_type = ($auction_type === 'commission') ? 3.0 : 5.0;
        try {
            // 1. Комиссия для конкретного лота
            if ($lot_id > 0) {
                $s = $pdo->prepare("SELECT rate_pct FROM commission_settings WHERE lot_id = ? LIMIT 1");
                $s->execute([$lot_id]);
                $r = $s->fetchColumn();
                if ($r !== false) return (float)$r;
            }
            // 2. Комиссия для конкретного организатора
            if ($user_id > 0) {
                $s = $pdo->prepare("SELECT rate_pct FROM commission_settings WHERE user_id = ? AND lot_id IS NULL LIMIT 1");
                $s->execute([$user_id]);
                $r = $s->fetchColumn();
                if ($r !== false) return (float)$r;
            }
            // 3. Глобальная по типу торгов (commission_settings.auction_type)
            if ($auction_type !== null && $auction_type !== '') {
                $s = $pdo->prepare("SELECT rate_pct FROM commission_settings
                                    WHERE user_id IS NULL AND lot_id IS NULL AND auction_type = ? LIMIT 1");
                try { $s->execute([$auction_type]); } catch (Throwable $e) { $s = null; }
                if ($s) {
                    $r = $s->fetchColumn();
                    if ($r !== false) return (float)$r;
                }
            }
            // 4. Глобальная дефолтная (auction_type IS NULL).
            //    Если колонки auction_type ещё нет — фолбэк на простой запрос.
            try {
                $s = $pdo->query("SELECT rate_pct FROM commission_settings
                                  WHERE user_id IS NULL AND lot_id IS NULL
                                    AND (auction_type IS NULL OR auction_type = '') LIMIT 1");
                $r = $s ? $s->fetchColumn() : false;
                if ($r !== false) return (float)$r;
            } catch (Throwable $e) {
                $s = $pdo->query("SELECT rate_pct FROM commission_settings
                                  WHERE user_id IS NULL AND lot_id IS NULL LIMIT 1");
                $r = $s ? $s->fetchColumn() : false;
                if ($r !== false) return (float)$r;
            }
            return $default_by_type;
        } catch (Exception $e) {
            return $default_by_type;
        }
    }

    /**
     * Рассчитывает финансовый итог скандинавского аукциона.
     *
     * @param float $start_price    Начальная цена лота
     * @param int   $total_bids     Количество принятых ставок
     * @param float $total_bid_revenue  Суммарная выручка от ставок (∑ стоимостей ставок)
     * @param float $sum_steps      Суммарный шаг (∑ шагов всех ставок)
     * @param float $deposit        Задаток победителя
     * @param float $commission_pct Комиссия площадки с организатора (%)
     *
     * @return array
     */
    function calcAuctionFinancials(
        float $start_price,
        int   $total_bids,
        float $total_bid_revenue,
        float $sum_steps,
        float $deposit,
        float $commission_pct
    ): array {
        // Итоговая цена = начальная + шаги + ставки
        $final_price = $start_price + $sum_steps + $total_bid_revenue;

        // Доход организатора (грязный) = начальная + шаги
        $organizer_gross = $start_price + $sum_steps;

        // Комиссия площадки с организатора
        $platform_commission = round($organizer_gross * $commission_pct / 100, 2);

        // Бонус организатору = 10% от выручки со ставок
        $organizer_bonus = round($total_bid_revenue * ORGANIZER_BONUS_PCT, 2);

        // Чистый доход организатора
        $organizer_net = $organizer_gross - $platform_commission + $organizer_bonus;

        // Доход площадки = выручка от ставок + комиссия с организатора - бонус
        $platform_net = $total_bid_revenue + $platform_commission - $organizer_bonus;

        // Победитель платит: итоговая цена − задаток (уже оплачен)
        $winner_pays = max(0, $final_price - $deposit);

        return [
            'final_price'         => round($final_price, 2),
            'start_price'         => $start_price,
            'sum_steps'           => round($sum_steps, 2),
            'total_bid_revenue'   => round($total_bid_revenue, 2),
            'organizer_gross'     => round($organizer_gross, 2),
            'platform_commission' => $platform_commission,
            'commission_pct'      => $commission_pct,
            'organizer_bonus'     => $organizer_bonus,
            'organizer_net'       => round($organizer_net, 2),
            'platform_net'        => round($platform_net, 2),
            'deposit'             => $deposit,
            'winner_pays'         => round($winner_pays, 2),
            'total_bids'          => $total_bids,
        ];
    }

    /**
     * Сохраняет финансовый итог аукциона в таблицу lot_financials.
     */
    function saveLotFinancials(PDO $pdo, int $lot_id, array $fin): bool {
        try {
            $pdo->prepare(
                "INSERT INTO lot_financials
                    (lot_id, final_price, start_price, sum_steps, total_bid_revenue,
                     organizer_gross, platform_commission, commission_pct,
                     organizer_bonus, organizer_net, platform_net,
                     deposit, winner_pays, total_bids, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE
                    final_price=VALUES(final_price),
                    organizer_net=VALUES(organizer_net),
                    platform_net=VALUES(platform_net),
                    winner_pays=VALUES(winner_pays)"
            )->execute([
                $lot_id,
                $fin['final_price'],
                $fin['start_price'],
                $fin['sum_steps'],
                $fin['total_bid_revenue'],
                $fin['organizer_gross'],
                $fin['platform_commission'],
                $fin['commission_pct'],
                $fin['organizer_bonus'],
                $fin['organizer_net'],
                $fin['platform_net'],
                $fin['deposit'],
                $fin['winner_pays'],
                $fin['total_bids'],
            ]);
            return true;
        } catch (Exception $e) {
            error_log("saveLotFinancials: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Проверяет бан пользователя.
     * Возвращает ['banned'=>true, 'type'=>'soft'|'hard', 'reason'=>'...'] или ['banned'=>false]
     */
    function checkUserBan(PDO $pdo, int $user_id): array {
        try {
            $s = $pdo->prepare(
                "SELECT ban_type, ban_reason, ban_until,
                        soft_bid_limit, soft_bid_window, soft_ban_msg
                 FROM users WHERE id = ? LIMIT 1"
            );
            $s->execute([$user_id]);
            $u = $s->fetch(PDO::FETCH_ASSOC);
            if (!$u) return ['banned' => false];

            // Жёсткий бан
            if ($u['ban_type'] === 'hard') {
                return [
                    'banned' => true,
                    'type'   => 'hard',
                    'reason' => $u['ban_reason'] ?? '',
                ];
            }

            // Мягкий бан с истёкшим сроком — снимаем
            if ($u['ban_type'] === 'soft' && !empty($u['ban_until'])) {
                if (strtotime($u['ban_until']) <= time()) {
                    $pdo->prepare("UPDATE users SET ban_type=NULL, ban_reason=NULL, ban_until=NULL WHERE id=?")
                        ->execute([$user_id]);
                    return ['banned' => false];
                }
                return [
                    'banned'    => true,
                    'type'      => 'soft',
                    'reason'    => $u['ban_reason'] ?? '',
                    'ban_until' => $u['ban_until'],
                    // Параметры мягкого бана для ставок
                    'soft_bid_limit'  => isset($u['soft_bid_limit']) ? (int)$u['soft_bid_limit'] : null,
                    'soft_bid_window' => (int)($u['soft_bid_window'] ?? 44),
                    'soft_ban_msg'    => $u['soft_ban_msg'] ?: 'Проверьте соединение с интернетом',
                ];
            }

            // Нет бана — но проверяем мягкие ограничения ставок
            if (isset($u['soft_bid_limit'])) {
                return [
                    'banned'          => false,
                    'soft_restricted' => true,
                    'soft_bid_limit'  => (int)$u['soft_bid_limit'],
                    'soft_bid_window' => (int)($u['soft_bid_window'] ?? 44),
                    'soft_ban_msg'    => $u['soft_ban_msg'] ?: 'Проверьте соединение с интернетом',
                ];
            }

            return ['banned' => false];
        } catch (Exception $e) {
            return ['banned' => false];
        }
    }
}
