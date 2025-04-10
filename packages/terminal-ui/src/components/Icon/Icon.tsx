import React from 'react';
import { cn } from '../lib/utils';

const ICONS = {
	keypad: (
		<svg
			height="16"
			viewBox="0 0 16 16"
			width="16"
			xmlns="http://www.w3.org/2000/svg"
			fill="currentColor" // Use currentColor to inherit text color
		>
			<path
				d="m4 0h8c1.1045695 0 2 .8954305 2 2v12c0 1.1045695-.8954305 2-2 2h-8c-1.1045695 0-2-.8954305-2-2v-12c0-1.1045695.8954305-2 2-2zm0 2v2.5h8v-2.5zm1 6c.55228475 0 1-.44771525 1-1s-.44771525-1-1-1-1 .44771525-1 1 .44771525 1 1 1zm3 0c.55228475 0 1-.44771525 1-1s-.44771525-1-1-1-1 .44771525-1 1 .44771525 1 1 1zm3 0c.5522847 0 1-.44771525 1-1s-.4477153-1-1-1-1 .44771525-1 1 .4477153 1 1 1zm-6 3c.55228475 0 1-.4477153 1-1 0-.55228475-.44771525-1-1-1s-1 .44771525-1 1c0 .5522847.44771525 1 1 1zm3 0c.55228475 0 1-.4477153 1-1 0-.55228475-.44771525-1-1-1s-1 .44771525-1 1c0 .5522847.44771525 1 1 1zm3 0c.5522847 0 1-.4477153 1-1 0-.55228475-.4477153-1-1-1s-1 .44771525-1 1 .4477153 1 1 1zm-6 3c.55228475 0 1-.4477153 1-1s-.44771525-1-1-1-1 .4477153-1 1 .44771525 1 1 1zm3 0c.55228475 0 1-.4477153 1-1s-.44771525-1-1-1-1 .4477153-1 1 .44771525 1 1 1zm3 0c.5522847 0 1-.4477153 1-1s-.4477153-1-1-1-1 .4477153-1 1 .4477153 1 1 1z"
				fillRule="evenodd"
			/>
		</svg>
	),
} as const;

interface IconProps extends React.HTMLAttributes<HTMLElement> {
	name: keyof typeof ICONS;
}

export const Icon = ({ name, className, ...props }: IconProps) => {
	return (
		<span className={cn('inline-flex', className)} {...props}>
			{ICONS[name]}
		</span>
	);
};
