import { type ButtonHTMLAttributes, forwardRef } from 'react';
import { cn } from '../lib/utils';
import { cva, type VariantProps } from 'class-variance-authority';

const buttonVariants = cva(
	'stwc-transition-all stwc-duration-200 stwc-ease-in-out focus:stwc-outline-none focus:stwc-ring-2 focus:stwc-ring-blue-200 focus:stwc-ring-offset-1',
	{
		variants: {
			variant: {
				primary:
					'stwc-flex stwc-h-7 stwc-cursor-pointer stwc-items-center stwc-justify-center stwc-border-0 stwc-rounded stwc-px-2 stwc-shadow-[0_0_0_1px_rgba(50,50,93,0.1),0_2px_5px_0_rgba(50,50,93,0.1),0_1px_1px_0_rgba(0,0,0,0.07)] stwc-bg-[#586ADA] hover:stwc-bg-[#484bad]',
				secondary:
					'stwc-flex stwc-h-7 stwc-cursor-pointer stwc-items-center stwc-justify-center stwc-border-0 stwc-rounded stwc-px-2 stwc-shadow-[0_0_0_1px_rgba(50,50,93,0.1),0_2px_5px_0_rgba(50,50,93,0.1),0_1px_1px_0_rgba(0,0,0,0.07)] stwc-bg-white hover:stwc-bg-[#F7FAFC]',
				text: 'stwc-cursor-pointer stwc-bg-transparent stwc-p-0 stwc-text-[11px] stwc-font-semibold stwc-uppercase stwc-tracking-[0.06px] stwc-text-[#586ADA] hover:stwc-text-[#9fcdff]',
				textDark:
					'stwc-cursor-pointer stwc-bg-transparent stwc-p-0 stwc-text-[11px] stwc-font-semibold stwc-uppercase stwc-tracking-[0.06px] stwc-text-[#78acf8] hover:stwc-text-[#9fcdff]',
			},
		},
		defaultVariants: {
			variant: 'primary',
		},
	}
);

interface ButtonProps
	extends ButtonHTMLAttributes<HTMLButtonElement>,
		VariantProps<typeof buttonVariants> {}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
	({ children, variant, className, disabled, ...props }, ref) => {
		return (
			<button
				ref={ref}
				disabled={disabled}
				className={cn(
					buttonVariants({ variant }),
					disabled && 'stwc-pointer-events-none stwc-opacity-50',
					className
				)}
				{...props}
			>
				{children}
			</button>
		);
	}
);

Button.displayName = 'Button';
