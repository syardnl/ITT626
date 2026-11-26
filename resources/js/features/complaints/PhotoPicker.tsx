import { useEffect, useMemo } from 'react';
import { photoLimits } from '../../lib/api';

interface Props {
    photos: File[];
    onChange: (photos: File[]) => void;
    error?: string;
}

const buttonClass = 'inline-flex min-h-11 cursor-pointer items-center rounded-md border border-slate-300 bg-white px-4 font-medium hover:bg-slate-50 focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-blue-600';

/**
 * Up to five photos from the camera or the gallery, with previews. The server re-checks
 * everything; the limits here only save a wasted upload on a slow phone connection.
 */
export function PhotoPicker({ photos, onChange, error }: Props) {
    const previews = useMemo(() => photos.map((photo) => URL.createObjectURL(photo)), [photos]);
    useEffect(() => () => previews.forEach((url) => URL.revokeObjectURL(url)), [previews]);

    const full = photos.length >= photoLimits.count;

    function add(files: FileList | null) {
        if (files) onChange([...photos, ...Array.from(files)].slice(0, photoLimits.count));
    }

    const problems = photos.flatMap((photo, index) => [
        ...(photoLimits.types.includes(photo.type as (typeof photoLimits.types)[number]) ? [] : [`Photo ${index + 1} is not a JPEG, PNG or WebP image.`]),
        ...(photo.size > photoLimits.megabytes * 1024 * 1024 ? [`Photo ${index + 1} is larger than ${photoLimits.megabytes} MB.`] : []),
    ]);

    return (
        <fieldset className="space-y-3" aria-describedby="photos-hint">
            <legend className="text-sm font-medium">Photos (optional)</legend>
            <p id="photos-hint" className="text-sm text-slate-600">
                Up to {photoLimits.count} photos, JPEG, PNG or WebP, {photoLimits.megabytes} MB each. Location data is removed before they are stored.
            </p>

            {!full && (
                <div className="flex flex-wrap gap-3">
                    <label className={buttonClass}>
                        Take a photo
                        <input
                            type="file" accept={photoLimits.types.join(',')} capture="environment" className="sr-only"
                            onChange={(e) => { add(e.target.files); e.target.value = ''; }}
                        />
                    </label>
                    <label className={buttonClass}>
                        Choose photos
                        <input
                            type="file" accept={photoLimits.types.join(',')} multiple className="sr-only"
                            onChange={(e) => { add(e.target.files); e.target.value = ''; }}
                        />
                    </label>
                </div>
            )}

            {photos.length > 0 && (
                <ul className="grid grid-cols-3 gap-3 sm:grid-cols-5">
                    {photos.map((photo, index) => (
                        <li key={previews[index]} className="space-y-1">
                            <img src={previews[index]} alt={`Photo ${index + 1}: ${photo.name}`} className="aspect-square w-full rounded-md object-cover" />
                            <button
                                type="button" onClick={() => onChange(photos.filter((_, other) => other !== index))}
                                className="min-h-11 w-full rounded-md text-sm text-red-700 underline hover:bg-red-50"
                            >
                                Remove
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {[...problems, ...(error ? [error] : [])].map((problem) => (
                <p key={problem} className="text-sm text-red-700">{problem}</p>
            ))}
        </fieldset>
    );
}
