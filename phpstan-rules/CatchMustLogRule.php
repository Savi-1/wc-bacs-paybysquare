<?php
declare(strict_types=1);

namespace Webikon\PhpStan;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags `catch` blocks that don't log, throw, or call a known logger method.
 *
 * Catching an exception and swallowing it (or just adding an order note) leaves
 * support staff with nothing to grep for. This rule enforces the "every catch
 * leaves a breadcrumb" convention.
 *
 * Recognised as a valid logging signal inside a catch:
 *   - `throw` (re-throw or new exception)
 *   - bare function call: `log(...)`, `error_log(...)`, `trigger_error(...)`, `wc_get_logger(...)`
 *   - method call: any PSR-3 level (`info`, `error`, `warning`, `debug`, ...) or local
 *     helpers `log`/`debug_log`. Receiver is not checked — we trust the method name.
 *
 * To suppress for a legitimately silent catch, place
 *   `// @phpstan-ignore-next-line catchMustLog.silentCatch`
 * immediately before the `catch` keyword, or move it to the baseline.
 *
 * @implements Rule<Node\Stmt\Catch_>
 */
final class CatchMustLogRule implements Rule
{
    /** Bare function calls counted as logging. */
    private const LOG_FUNCTIONS = [
        'log',
        'debug_log',
        'error_log',
        'trigger_error',
        'wc_get_logger',
    ];

    /** Method names counted as logging (PSR-3 levels + local helpers). */
    private const LOG_METHODS = [
        'log',
        'debug_log',
        'emergency',
        'alert',
        'critical',
        'error',
        'warning',
        'notice',
        'info',
        'debug',
    ];

    public function getNodeType(): string
    {
        return Node\Stmt\Catch_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->bodyLogsOrThrows($node->stmts)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Catch block does not log, throw, or call a known logger method. '
                . 'Add a log() / $this->log() / wc_get_logger()->info() call so failures '
                . 'leave a breadcrumb in the WooCommerce logs.'
            )
                ->identifier('catchMustLog.silentCatch')
                ->build(),
        ];
    }

    /**
     * @param Node\Stmt[] $stmts
     */
    private function bodyLogsOrThrows(array $stmts): bool
    {
        $finder = new NodeFinder();
        $found  = $finder->findFirst($stmts, function (Node $n): bool {
            if ($n instanceof Node\Stmt\Throw_ || $n instanceof Node\Expr\Throw_) {
                return true;
            }
            if ($n instanceof Node\Expr\FuncCall && $n->name instanceof Node\Name) {
                if (in_array(strtolower($n->name->getLast()), self::LOG_FUNCTIONS, true)) {
                    return true;
                }
            }
            if ($n instanceof Node\Expr\MethodCall && $n->name instanceof Node\Identifier) {
                if (in_array(strtolower($n->name->toString()), self::LOG_METHODS, true)) {
                    return true;
                }
            }
            if ($n instanceof Node\Expr\StaticCall && $n->name instanceof Node\Identifier) {
                if (in_array(strtolower($n->name->toString()), self::LOG_METHODS, true)) {
                    return true;
                }
            }
            return false;
        });

        return $found !== null;
    }
}
