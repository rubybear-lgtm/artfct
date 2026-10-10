import { router } from '@inertiajs/react';
import { Copy } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import ArtifactViewerController from '@/actions/App/Http/Controllers/ArtifactViewerController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

export type ArtifactSharing = 'private' | 'team' | 'public';

export type ArtifactEditAccess = 'view' | 'edit';

interface ShareOption<T extends string> {
    value: T;
    label: string;
    description: string;
}

const sharingOptions: ShareOption<ArtifactSharing>[] = [
    {
        value: 'private',
        label: 'Private',
        description: 'Only you and team admins',
    },
    { value: 'team', label: 'Team', description: 'Everyone on your team' },
    {
        value: 'public',
        label: 'Public',
        description: 'Anyone with the link',
    },
];

const editOptions: ShareOption<ArtifactEditAccess>[] = [
    {
        value: 'view',
        label: 'Can view',
        description: 'People it is shared with can read it',
    },
    {
        value: 'edit',
        label: 'Can edit',
        description: 'People it is shared with can publish new versions',
    },
];

export function sharingLabel(sharing: ArtifactSharing | null): string {
    return (
        sharingOptions.find((option) => option.value === sharing)?.label ??
        'Team'
    );
}

interface Props {
    artifactId: string;
    sharing: ArtifactSharing | null;
    editAccess: ArtifactEditAccess | null;
    publicSharingAllowed: boolean;
    canChangeSharing: boolean;
    /** The absolute viewer URL for the "copy link" control. */
    viewerUrl: string;
}

/**
 * The sharing control above an artifact: who can open it and whether the
 * people it is shared with can publish new versions. Only the owner or a team
 * admin sees the choices — everyone else gets the current level as a plain
 * label. The Worker is the authority on both changes; this only sends them.
 */
export default function ArtifactShareControl({
    artifactId,
    sharing,
    editAccess,
    publicSharingAllowed,
    canChangeSharing,
    viewerUrl,
}: Props) {
    const [open, setOpen] = useState(false);
    const currentSharing: ArtifactSharing = sharing ?? 'team';
    const currentEditAccess: ArtifactEditAccess = editAccess ?? 'view';

    if (!canChangeSharing) {
        return (
            <span
                className="text-sm text-muted-foreground"
                data-testid="artifact-sharing-label"
            >
                {sharingLabel(sharing)}
            </span>
        );
    }

    const submit = (
        changes:
            | { sharing: ArtifactSharing }
            | { edit_access: ArtifactEditAccess },
    ) => {
        router.patch(
            ArtifactViewerController.updateSharing.url({ artifactId }),
            changes,
            { preserveScroll: true, preserveState: true },
        );
    };

    const copyLink = async () => {
        await navigator.clipboard.writeText(viewerUrl);
        toast.success('Link copied.');
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" data-testid="share-control">
                    Share
                </Button>
            </DialogTrigger>
            <DialogContent aria-label="Sharing">
                <DialogTitle>Share</DialogTitle>
                <DialogDescription>
                    Choose who can open this artifact.
                </DialogDescription>

                <fieldset className="mt-5">
                    <legend className="text-sm font-semibold text-foreground">
                        Who can open it
                    </legend>
                    <div className="mt-3 flex flex-col gap-3">
                        {sharingOptions.map((option) => {
                            const disabled =
                                option.value === 'public' &&
                                !publicSharingAllowed;

                            return (
                                <label
                                    key={option.value}
                                    className={cn(
                                        'flex items-start gap-3',
                                        disabled && 'opacity-60',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="sharing"
                                        value={option.value}
                                        checked={
                                            currentSharing === option.value
                                        }
                                        disabled={disabled}
                                        onChange={() =>
                                            submit({
                                                sharing: option.value,
                                            })
                                        }
                                        data-testid={`share-option-${option.value}`}
                                        className="mt-1 size-4 accent-[var(--color-primary)]"
                                    />
                                    <span className="min-w-0">
                                        <span className="block text-sm font-semibold text-foreground">
                                            {option.label}
                                        </span>
                                        <span className="block text-sm text-muted-foreground">
                                            {disabled
                                                ? 'Your team has turned off public links'
                                                : option.description}
                                        </span>
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                </fieldset>

                <fieldset className="mt-6">
                    <legend className="text-sm font-semibold text-foreground">
                        What they can do
                    </legend>
                    <div className="mt-3 flex flex-col gap-3">
                        {editOptions.map((option) => (
                            <label
                                key={option.value}
                                className="flex items-start gap-3"
                            >
                                <input
                                    type="radio"
                                    name="edit_access"
                                    value={option.value}
                                    checked={currentEditAccess === option.value}
                                    onChange={() =>
                                        submit({
                                            edit_access: option.value,
                                        })
                                    }
                                    data-testid={`edit-option-${option.value}`}
                                    className="mt-1 size-4 accent-[var(--color-primary)]"
                                />
                                <span className="min-w-0">
                                    <span className="block text-sm font-semibold text-foreground">
                                        {option.label}
                                    </span>
                                    <span className="block text-sm text-muted-foreground">
                                        {option.description}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </div>
                </fieldset>

                <div className="mt-6 flex items-center justify-between gap-3 border-t border-border pt-4">
                    <div className="min-w-0">
                        <p className="text-sm font-semibold text-foreground">
                            Link
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                            {viewerUrl}
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={copyLink}
                        data-testid="copy-link"
                    >
                        <Copy className="size-3.5" /> Copy link
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
