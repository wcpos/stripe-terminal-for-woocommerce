import { type HTMLAttributes, forwardRef } from 'react';
import { cn } from '../lib/utils';

interface GroupProps extends HTMLAttributes<HTMLDivElement> {
	title?: string;
	description?: string;
}

export const Group = forwardRef<HTMLDivElement, GroupProps>(
	({ title, description, children, className, ...props }, ref) => {
		return (
			<div
				ref={ref}
				className={cn(
					'stwc-mb-4 stwc-rounded-lg stwc-border stwc-border-gray-200 stwc-bg-white',
					className
				)}
				{...props}
			>
				{(title || description) && (
					<div className="stwc-mb-4">
						{title && (
							<h3 className="stwc-mb-1 stwc-text-base stwc-font-medium stwc-text-gray-900">
								{title}
							</h3>
						)}
						{description && <p className="stwc-text-sm stwc-text-gray-500">{description}</p>}
					</div>
				)}
				{children}
			</div>
		);
	}
);

Group.displayName = 'Group';
