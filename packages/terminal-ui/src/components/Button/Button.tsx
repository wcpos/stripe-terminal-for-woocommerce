import { type ButtonHTMLAttributes, forwardRef } from 'react';
import { cn } from '../lib/utils';
import { cva, type VariantProps } from 'class-variance-authority';

const buttonVariants = cva(
	'stwc-transition-all stwc-duration-200 stwc-ease-in-out focus:stwc-outline-none focus:stwc-ring-2 focus:stwc-ring-blue-200 focus:stwc-ring-offset-2',
	{
		variants: {
			variant: {
				primary:
					'stwc-flex stwc-h-8 stwc-cursor-pointer stwc-items-center stwc-justify-center stwc-rounded-md stwc-border stwc-border-transparent stwc-bg-[#586ADA] stwc-px-4 stwc-text-white stwc-text-[14px] stwc-font-medium stwc-shadow-[0_1px_3px_rgba(0,0,0,0.12),0_1px_2px_rgba(0,0,0,0.24)] hover:stwc-bg-[#4858B8] active:stwc-bg-[#3C4B9A]',
				secondary:
					'stwc-flex stwc-h-8 stwc-cursor-pointer stwc-items-center stwc-justify-center stwc-rounded-md stwc-border stwc-border-[#E0E4E9] stwc-bg-white stwc-px-4 stwc-text-[#323A46] stwc-text-[14px] stwc-font-medium stwc-shadow-[0_1px_3px_rgba(0,0,0,0.12),0_1px_2px_rgba(0,0,0,0.24)] hover:stwc-bg-[#F7FAFC] active:stwc-bg-[#E5E9EF]',
				text: 'stwc-cursor-pointer stwc-bg-transparent stwc-p-0 stwc-text-[14px] stwc-font-semibold stwc-uppercase stwc-text-[#586ADA] hover:stwc-text-[#4858B8]',
			},
		},
		defaultVariants: {
			variant: 'secondary',
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
				type="button" // Prevent the button from submitting the form
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
