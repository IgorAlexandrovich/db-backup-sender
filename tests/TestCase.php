<?php

declare(strict_types=1);

namespace YourVendor\DbBackup\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use YourVendor\DbBackup\BackupServiceProvider;

/**
 * Базовый класс тестов пакета.
 *
 * Поднимает минимальное приложение Laravel (Testbench) с провайдером пакета,
 * чтобы команды и сервисы работали так же, как в реальном проекте.
 */
abstract class TestCase extends Orchestra
{
    /**
     * Провайдеры, которые нужно загрузить.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [BackupServiceProvider::class];
    }
}
