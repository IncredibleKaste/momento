import type { SVGAttributes } from 'react';
import { cn } from '@/lib/utils';

export default function MomentoWordmark({
    className,
    ...props
}: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            className={cn('h-12 w-44 shrink-0', className)}
            viewBox="0 0 184 48"
            aria-hidden="true"
            xmlns="http://www.w3.org/2000/svg"
        >
            <g
                fontFamily="'EB Garamond', Georgia, serif"
                fontSize="43"
                fontWeight="600"
                letterSpacing="-1.1"
            >
                <text x="7" y="38" fill="#4c85a7">
                    Momento
                </text>
                <text x="6" y="37" fill="#87619b">
                    Momento
                </text>
                <text x="5" y="36" fill="#be5350">
                    Momento
                </text>
                <text x="4" y="35" fill="#d9b350">
                    Momento
                </text>
                <text x="3" y="34" fill="#648b49">
                    Momento
                </text>
                <text x="2" y="33" fill="currentColor">
                    Momento
                </text>
            </g>
        </svg>
    );
}
