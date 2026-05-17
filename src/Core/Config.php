<?php

declare(strict_types=1);

namespace Recombinator\Core;

/**
 * Конфигурация процесса рекомбинации.
 *
 * Основное назначение — описание «публичного контракта»: список FQN
 * (классов, функций, методов вида `App\Service\Foo::bar`), которые
 * образуют внешний API кодовой базы.
 *
 * Публичный контракт ни при каких условиях не должен нарушаться в процессе
 * преобразований: защищённые символы не удаляются как «неиспользуемые» и не
 * инлайнятся, даже если внутри анализируемого кода у них нет ни одного
 * обращения (вызов может приходить извне).
 *
 * Пример:
 * ```php
 * $config = new Config()->setPublicContract([
 *     'App\\Domain\\Money',
 *     'App\\Service\\PaymentGateway::charge',
 *     'app_bootstrap',
 * ]);
 * ```
 */
final class Config
{
    /**
     * Нормализованные записи публичного контракта.
     *
     * @var array<string, true>
     */
    private array $publicContract = [];

    /**
     * Задаёт публичный контракт — массив FQN, которые нельзя нарушать.
     *
     * Принимаются классы (`App\Foo`), функции (`app_helper`) и методы
     * (`App\Foo::bar`). Регистр и ведущий обратный слеш не важны.
     *
     * @param array<int, string> $fqns
     */
    public function setPublicContract(array $fqns): self
    {
        foreach ($fqns as $fqn) {
            $normalized = $this->normalize($fqn);
            if ($normalized !== '') {
                $this->publicContract[$normalized] = true;
            }
        }

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getPublicContract(): array
    {
        return array_keys($this->publicContract);
    }

    /**
     * Входит ли символ (класс или функция по короткому или полному имени)
     * в публичный контракт.
     *
     * Сопоставление толерантно к пространствам имён: т.к. в пайплайне нет
     * резолва namespace, имя в AST обычно короткое. Поэтому совпадением
     * считается как полное равенство, так и совпадение по последнему
     * сегменту FQN. Если в контракте указан метод (`App\Foo::bar`),
     * защищённым считается весь класс `App\Foo`.
     */
    public function isProtected(string $name): bool
    {
        if ($this->publicContract === []) {
            return false;
        }

        $needle = $this->normalize($name);
        if ($needle === '') {
            return false;
        }

        foreach (array_keys($this->publicContract) as $entry) {
            if ($entry === $needle) {
                return true;
            }

            $classPart = explode('::', $entry, 2)[0];
            if ($classPart === $needle) {
                return true;
            }

            $segments = explode('\\', $classPart);
            if (end($segments) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $fqn): string
    {
        return strtolower(trim($fqn, " \t\n\r\0\x0B\\"));
    }
}
