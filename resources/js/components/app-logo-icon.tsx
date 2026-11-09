import type { SVGAttributes } from 'react';
import { useId } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    const clip = useId();

    return (
        <svg
            {...props}
            viewBox="0 0 40 40"
            aria-hidden="true"
            xmlns="http://www.w3.org/2000/svg"
        >
            <defs>
                <clipPath id={clip}>
                    <path d="M4 32V8h8l8 12 8-12h8v24h-8V21l-8 12-8-12v11Z" />
                </clipPath>
            </defs>
            <g clipPath={`url(#${clip})`}>
                <path fill="#648b49" d="M4 8h32v5H4z" />
                <path fill="#d9b350" d="M4 13h32v5H4z" />
                <path fill="#be5350" d="M4 18h32v5H4z" />
                <path fill="#87619b" d="M4 23h32v5H4z" />
                <path fill="#4c85a7" d="M4 28h32v5H4z" />
            </g>
        </svg>
    );
}
