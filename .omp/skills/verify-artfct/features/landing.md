# Landing page

## Sub-features

Hero demo, responsive layout and agent connection guidance.

## How to get to it (user POV)

Open the public home page at `/`. Scroll through the demo and down to “Connect your agent.”

## Driving it with Pest Browser

Run `php artisan test --compact tests/Browser/LandingFlowBrowserTest.php`. The tests check the demo at `@landing-flow`, its phone layout and the setup prompt in the connection section.

## Gotchas

Run against freshly built frontend assets. This journey needs no login.
