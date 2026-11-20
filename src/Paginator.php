<?php

declare(strict_types=1);

namespace Cbox\Billing;

use Generator;
use UnexpectedValueException;

final class Paginator
{
    /**
     * Every item of a cursor-paginated list, fetching the next page only when needed.
     *
     *     foreach (Paginator::items(fn (?string $cursor) => $invoices->listInvoices('org_acme', cursor: $cursor)) as $invoice) {
     *         …
     *     }
     *
     * `$fetchPage` receives null for the first page and the previous page's `next_cursor` after,
     * and returns a generated list model (it has `getHasMore()`, `getNextCursor()`, `getData()`).
     *
     * @param  callable(?string): object  $fetchPage
     * @return Generator<int, mixed>
     */
    public static function items(callable $fetchPage): Generator
    {
        $cursor = null;

        while (true) {
            $page = $fetchPage($cursor);

            if (! method_exists($page, 'getData') || ! method_exists($page, 'getHasMore') || ! method_exists($page, 'getNextCursor')) {
                throw new UnexpectedValueException('The page has no cursor fields; is this a list endpoint?');
            }

            foreach ($page->getData() as $item) {
                yield $item;
            }

            $next = $page->getNextCursor();
            if (! $page->getHasMore() || $next === null || $next === '') {
                return;
            }
            $cursor = $next;
        }
    }
}
