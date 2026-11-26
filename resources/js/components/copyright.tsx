/**
 * "© 2026 Artfct". The server renders the year in UTC and the browser in the
 * reader's time zone, so for a few hours around New Year they disagree. That is
 * harmless here, so React is told to leave the text alone instead of refusing to
 * hydrate the whole page (error 418). In that window the server's year stays.
 */
export default function Copyright() {
    return (
        <span suppressHydrationWarning>
            {`© ${new Date().getFullYear()} Artfct`}
        </span>
    );
}
