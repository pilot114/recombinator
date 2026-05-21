<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Удаляет `use function foo;` и `use const FOO;` для символов из глобального
 * неймспейса.
 *
 * После того как пайплайн расплющивает код в глобальный неймспейс, такой импорт
 * избыточен: `foo()` / `FOO` и без `use` резолвятся в `\foo` / `\FOO`. Удаление
 * безопасно, только если:
 *   - имя однокомпонентное (без `\`);
 *   - нет алиаса (`use function bar as baz;` менял бы резолв);
 *   - объявление находится не внутри именованного `namespace X { ... }`
 *     (там удаление меняло бы семантику: `foo()` начал бы резолвить в `\X\foo`).
 *
 * Многокомпонентные импорты (`use function Foo\bar;`) и алиасы оставляем как есть —
 * их удаление сломало бы вызовы.
 *
 * `use Foo\Bar;` (TYPE_NORMAL) не трогаем — это задача UseImportVisitor.
 */
#[VisitorMeta('Удаление use function / use const для глобальных символов')]
class RemoveGlobalUseVisitor extends BaseVisitor
{
    public function leaveNode(Node $node): int|Node|array|null
    {
        if (
            $node instanceof Node\Stmt\Use_
            && ($node->type === Node\Stmt\Use_::TYPE_FUNCTION || $node->type === Node\Stmt\Use_::TYPE_CONSTANT)
        ) {
            $parent = $node->getAttribute('parent');
            if (!($parent instanceof Node\Stmt\Namespace_ && $parent->name !== null)) {
                $kept = [];
                foreach ($node->uses as $use) {
                    if (!$this->isRedundant($use)) {
                        $kept[] = $use;
                    }
                }

                if ($kept === []) {
                    $node->setAttribute('remove', true);
                } elseif (count($kept) !== count($node->uses)) {
                    $node->uses = $kept;
                }
            }
        }

        return parent::leaveNode($node);
    }

    /**
     * Однокомпонентный импорт без алиаса → дублирует поведение глобального
     * резолва и может быть удалён.
     */
    private function isRedundant(Node\UseItem $use): bool
    {
        if (count($use->name->getParts()) !== 1) {
            return false;
        }

        return $use->alias === null;
    }
}
