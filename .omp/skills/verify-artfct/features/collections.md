# Collections

## Sub-features

Collection counts, artifact stacks and the collection modal.

## How to get to it (user POV)

Sign in to the console and open Collections. Select a collection to see its artifacts, then close the modal.

## Driving it with Pest Browser

Run `php artisan test --compact tests/Browser/CollectionsBrowserTest.php`. The suite seeds a populated and an empty collection, opens a stack with `@collection-toggle-<id>` and checks that the modal shows the seeded artifacts and closes.

## Gotchas

Use the seeded collection ID. The test also checks opening and closing the modal at phone widths.
