import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';

type ConfirmDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    /** What happens, in plain words, including what cannot be undone. */
    description: ReactNode;
    /** Says exactly what happens, e.g. "Cancel subscription", never "OK". */
    confirmLabel: string;
    onConfirm: () => void;
    cancelLabel?: string;
    destructive?: boolean;
    processing?: boolean;
};

/**
 * The single confirmation pattern for destructive or hard-to-undo actions.
 */
export function ConfirmDialog({
    open,
    onOpenChange,
    title,
    description,
    confirmLabel,
    onConfirm,
    cancelLabel = 'Keep it',
    destructive = true,
    processing = false,
}: ConfirmDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription asChild>
                    <div>{description}</div>
                </DialogDescription>
                <div className="mt-5 flex flex-wrap justify-end gap-2">
                    <DialogClose asChild>
                        <Button variant="outline">{cancelLabel}</Button>
                    </DialogClose>
                    <Button
                        variant={destructive ? 'destructive' : 'default'}
                        disabled={processing}
                        onClick={onConfirm}
                    >
                        {confirmLabel}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
