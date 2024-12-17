import { type HTMLAttributes } from 'react';
import { cn } from '../lib/utils';
import { Text } from '../Text/Text';

interface SectionProps extends HTMLAttributes<HTMLDivElement> {
	title?: string;
	description?: string;
}

export const Section = ({ title, description, children, className, ...props }: SectionProps) => {
	return (
		<div className={cn('stwc-mb-8', className)} {...props}>
			{(title || description) && (
				<div className="stwc-mb-4">
					{title && <Text className="stwc-mb-1 stwc-text-base">{title}</Text>}
					{description && <Text color="muted">{description}</Text>}
				</div>
			)}
			{children}
		</div>
	);
};
