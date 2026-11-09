import { Head } from '@inertiajs/react';
import PhotoPostComposer from './composer';
import type { PhotoPostLimits } from './types';

type Props = {
    decoderAvailable: boolean;
    limits: PhotoPostLimits;
};

export default function CreatePhotoPost({ decoderAvailable, limits }: Props) {
    return (
        <>
            <Head title="Share a photo" />
            <div className="space-y-6 py-8">
                <header className="space-y-2">
                    <h1 className="font-serif text-3xl tracking-tight sm:text-4xl">
                        Share a photo
                    </h1>
                    <p className="max-w-xl text-sm leading-relaxed text-muted-foreground sm:text-base">
                        Add one photo and an optional caption. Your photo is
                        checked and prepared before it is available.
                    </p>
                </header>
                <PhotoPostComposer
                    limits={limits}
                    decoderAvailable={decoderAvailable}
                />
            </div>
        </>
    );
}
