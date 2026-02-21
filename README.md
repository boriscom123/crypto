# Crypto Balance

### 1. Скопируйте файлы в проект

```bash
# Модели
cp app/Models/Wallet.php app/Models/Transaction.php app/Models/RiskLog.php app/Models/

# Сервисы
cp app/Services/CryptoBalanceService.php app/Services/RiskAssessmentService.php app/Services/

# Контроллер
cp app/Http/Controllers/Api/CryptoBalanceController.php app/Http/Controllers/Api/

# Провайдер
cp app/Providers/CryptoServiceProvider.php app/Providers/

# Конфигурация
cp config/crypto.php config/

# Миграции
cp database/migrations/*_create_*_table.php database/migrations/

# Маршруты
# Добавьте содержимое routes/api.php в ваш routes/api.php

# Тесты
cp tests/Unit/Services/*Test.php tests/Unit/Services/
```

### 2. Запустите миграции

```bash
docker exec -w /var/www/crypto Projects-php php artisan migrate
```

### 3. Зарегистрируйте ServiceProvider

В `config/app.php` добавьте в массив `providers`:

```php
App\Providers\CryptoServiceProvider::class,
```

### 4. Опубликуйте конфигурацию (опционально)

```bash
docker exec -w /var/www/crypto Projects-php php artisan vendor:publish --provider="App\Providers\CryptoServiceProvider" --tag="crypto-config"
```

## Конфигурация

Файл `config/crypto.php` содержит настройки:

### Комиссии

```php
'fees' => [
    'btc' => [
        'percent' => 0.001,  // 0.1%
        'min' => 0.0001,     // минимальная комиссия
    ],
    // ...
],
```

### Оценка рисков

```php
'risk' => [
    'large_amount_threshold' => [
        'btc' => '1',  // порог крупной суммы
    ],
    'frequent_transactions_limit' => 10,
    'daily_limit' => [
        'btc' => '10',
    ],
    'hourly_limit' => [
        'btc' => '1',
    ],
],
```

### Черный список адресов

```php
'blacklist' => [
    '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa',
    // ...
],
```

## API Endpoints

Все endpoints требуют аутентификации через `auth:sanctum`.

### Балансы

| Метод | Endpoint | Описание |
|-------|----------|----------|
| GET | `/api/balances` | Получить все балансы пользователя |
| GET | `/api/wallet/{id}/balance` | Получить баланс конкретного кошелька |
| POST | `/api/wallet` | Создать новый кошелек |

### Транзакции

| Метод | Endpoint | Описание |
|-------|----------|----------|
| GET | `/api/transactions` | История транзакций |
| GET | `/api/transactions/{id}` | Детали транзакции |

### Депозит

```http
POST /api/deposit
Content-Type: application/json

{
    "wallet_id": 1,
    "amount": "1.5",
    "tx_hash": "0x...",
    "from_address": "sender_address"
}
```

### Вывод средств

```http
POST /api/withdraw
Content-Type: application/json

{
    "wallet_id": 1,
    "amount": "0.5",
    "to_address": "recipient_address",
    "description": "Withdrawal request"
}
```

Ответ может содержать статусы:
- `pending` - ожидает подтверждения
- `risk_review` - на проверке службой безопасности
- `failed` - отклонено системой рисков

### Подтверждение/отмена вывода

```http
POST /api/withdraw/{id}/confirm
POST /api/withdraw/{id}/cancel
```

### Внутренний платеж

```http
POST /api/payment
Content-Type: application/json

{
    "from_wallet_id": 1,
    "to_address": "recipient_wallet_address",
    "amount": "0.1",
    "description": "Payment for services"
}
```

### Риски

| Метод | Endpoint | Описание |
|-------|----------|----------|
| GET | `/api/risk/stats` | Статистика рисков пользователя |
| GET | `/api/risk/history` | История проверок рисков |

## Использование сервисов

### CryptoBalanceService

```php
use App\Services\CryptoBalanceService;

class MyController extends Controller
{
    public function __construct(
        protected CryptoBalanceService $balanceService
    ) {}

    public function deposit(Request $request)
    {
        $wallet = Wallet::findOrFail($request->wallet_id);

        $transaction = $this->balanceService->deposit(
            $wallet,
            $request->amount,
            $request->tx_hash,
            $request->from_address
        );

        return response()->json(['transaction' => $transaction]);
    }

    public function withdraw(Request $request)
    {
        $wallet = Wallet::findOrFail($request->wallet_id);

        try {
            $transaction = $this->balanceService->withdraw(
                $wallet,
                $request->amount,
                $request->to_address
            );

            return response()->json(['transaction' => $transaction]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function payment(Request $request)
    {
        $fromWallet = Wallet::findOrFail($request->from_wallet_id);
        $toWallet = Wallet::where('address', $request->to_address)->firstOrFail();

        $transactions = $this->balanceService->payment(
            $fromWallet,
            $toWallet,
            $request->amount,
            $request->description
        );

        return response()->json(['transactions' => $transactions]);
    }
}
```

### RiskAssessmentService

```php
use App\Services\RiskAssessmentService;

class MyController extends Controller
{
    public function __construct(
        protected RiskAssessmentService $riskService
    ) {}

    public function getUserRiskProfile(int $userId)
    {
        $stats = $this->riskService->getUserRiskStats($userId);
        $history = $this->riskService->getUserRiskHistory($userId, 100);

        return response()->json([
            'stats' => $stats,
            'history' => $history,
        ]);
    }
}
```

## Модель данных

### Wallet

| Поле | Тип | Описание |
|------|-----|----------|
| id | bigint | ID кошелька |
| user_id | bigint | ID пользователя |
| currency | string | Валюта (BTC, ETH, USDT...) |
| address | string | Адрес кошелька |
| balance | decimal | Общий баланс |
| locked_balance | decimal | Заблокированный баланс |
| status | enum | active/frozen/closed |

### Transaction

| Поле | Тип | Описание |
|------|-----|----------|
| id | bigint | ID транзакции |
| wallet_id | bigint | ID кошелька |
| user_id | bigint | ID пользователя |
| type | enum | deposit/withdraw/payment/fee/transfer |
| amount | decimal | Сумма |
| fee | decimal | Комиссия |
| status | enum | pending/processing/completed/failed/cancelled/risk_review |
| tx_hash | string | Хэш транзакции в блокчейне |
| from_address | string | Адрес отправителя |
| to_address | string | Адрес получателя |
| risk_score | decimal | Оценка риска (0-1) |
| risk_level | enum | low/medium/high/critical |

### RiskLog

| Поле | Тип | Описание |
|------|-----|----------|
| id | bigint | ID записи |
| transaction_id | bigint | ID связанной транзакции |
| wallet_id | bigint | ID кошелька |
| user_id | bigint | ID пользователя |
| risk_score | decimal | Оценка риска (0-1) |
| risk_level | enum | low/medium/high/critical |
| risk_factors | json | Факторы риска |
| decision | enum | approved/rejected/review/auto_approved |
| reviewed_by | bigint | ID проверившего менеджера |
| review_notes | text | Заметки проверки |

## Факторы риска

Система оценивает следующие факторы:

| Фактор | Вес | Описание |
|--------|-----|----------|
| blacklisted_address | 0.3 | Адрес в черном списке |
| large_amount | 0.25 | Крупная сумма |
| new_address | 0.2 | Новый адрес получателя |
| suspicious_pattern | 0.2 | Подозрительный паттерн (smurfing) |
| frequent_transactions | 0.15 | Частые транзакции |
| velocity_check | 0.15 | Превышение лимита скорости |
| wallet_age | 0.1 | Новый кошелек |
| unusual_time | 0.1 | Необычное время операции |

## Уровни риска

| Уровень | Диапазон | Решение |
|---------|----------|---------|
| low | 0.0 - 0.3 | auto_approved |
| medium | 0.3 - 0.6 | approved |
| high | 0.6 - 0.8 | review |
| critical | 0.8 - 1.0 | rejected |

## Тесты

Запуск тестов:

```bash
docker exec -w /var/www/crypto Projects-php php artisan test --filter=CryptoBalanceServiceTest
docker exec -w /var/www/crypto Projects-php php artisan test --filter=RiskAssessmentServiceTest
```

## Безопасность

1. Все операции с балансом выполняются в транзакциях БД
2. Используется точная арифметика (BCMath) для крипто-сумм
3. Блокировка средств при выводе до подтверждения
4. Многофакторная оценка рисков
5. Логирование всех операций

## Лицензия

MIT
