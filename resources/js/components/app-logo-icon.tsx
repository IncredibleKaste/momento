import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 40 40"
            aria-hidden="true"
            xmlns="http://www.w3.org/2000/svg"
        >
            <text
                x="2"
                y="30"
                fontFamily="Georgia, serif"
                fontSize="38"
                fill="currentColor"
            >
                m.
            </text>
        </svg>
    );
}
