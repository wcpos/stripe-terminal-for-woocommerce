import { type ButtonHTMLAttributes } from 'react';
import { cn } from '../lib/utils';

interface OverflowMenuItemProps extends ButtonHTMLAttributes<HTMLButtonElement> {
	destructive?: boolean;
}

export const OverflowMenuItem = ({
	children,
	className,
	destructive = false,
	...props
}: OverflowMenuItemProps) => {
	return (
		<button
			className={cn(
				'stwc-block stwc-w-full stwc-px-4 stwc-py-2 stwc-text-left stwc-text-sm hover:stwc-bg-[#F7FAFC]',
				destructive ? 'stwc-text-[#CD3D64]' : 'stwc-text-[#1A1F36]',
				className
			)}
			{...props}
		>
			{children}
		</button>
	);
};
