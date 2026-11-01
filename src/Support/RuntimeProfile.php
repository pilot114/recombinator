<?php

declare(strict_types=1);

namespace Recombinator\Support;

/**
 * Что выполнение проекта на запросах профиля (CoverageProbe) знает о классах, которых
 * код программы не называет: их загружают по именам из данных или их объявляет код,
 * подключаемый во время выполнения (кэш контейнера Symfony в var/).
 */
final readonly class RuntimeProfile
{
    /**
     * @param list<string> $classes  конкретные классы, загружаемые по имени: встраиваются
     * @param list<string> $types    все загруженные типы (и трейты, абстрактные): не удаляются —
     *                               их методы вызывает подключаемый код
     * @param list<string> $extended классы и интерфейсы, которые наследует подключаемый код:
     *                               вызов их метода нельзя девиртуализировать
     */
    public function __construct(
        public array $classes = [],
        public array $types = [],
        public array $extended = [],
    ) {
    }

    /** @return array<string, true> плоские lowercase имена из $extended */
    public function extendedFlat(): array
    {
        return array_fill_keys(array_map(static fn(string $name): string => strtolower(strtr(ltrim($name, '\\'), ['\\' => '_'])), $this->extended), true);
    }
}
