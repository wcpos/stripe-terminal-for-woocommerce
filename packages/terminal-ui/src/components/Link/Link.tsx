import { type AnchorHTMLAttributes } from 'react';
import { cn } from '../lib/utils';

interface LinkProps extends AnchorHTMLAttributes<HTMLAnchorElement> {
	newWindow?: boolean;
}

export const Link = ({ className, children, newWindow, ...props }: LinkProps) => {
	return (
		<a
			className={cn('stwc-text-blue-400 stwc-no-underline hover:stwc-underline', className)}
			target={newWindow ? '_blank' : props.target}
			rel={newWindow ? 'noopener noreferrer' : props.rel}
			{...props}
		>
			{children}
		</a>
	);
};
