import { Toaster as Sonner } from 'sonner';

export function Toaster() {
    return <Sonner position="bottom-right" toastOptions={{ classNames: { toast: 'border border-border bg-background text-foreground' } }} />;
}
