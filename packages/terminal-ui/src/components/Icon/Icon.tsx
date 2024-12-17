import { type HTMLAttributes } from 'react';
import { cn } from '../lib/utils';

const ICONS = {
	refresh: new URL('./svg/icon-refresh.svg', import.meta.url).href,
} as const;

interface IconProps extends HTMLAttributes<HTMLObjectElement> {
	name: keyof typeof ICONS;
}

export const Icon = ({ name, className, ...props }: IconProps) => {
	return (
		<object className={cn('h-4 w-4', className)} data={ICONS[name]} tabIndex={-1} {...props}>
			""
		</object>
	);
};
