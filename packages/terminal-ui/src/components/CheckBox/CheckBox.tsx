import { type InputHTMLAttributes, forwardRef } from 'react';
import { cn } from '../lib/utils';

interface CheckBoxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
	label?: string;
}

export const CheckBox = forwardRef<HTMLInputElement, CheckBoxProps>(
	({ className, label, disabled, ...props }, ref) => {
		return (
			<label
				className={cn(
					'stwc-relative stwc-flex stwc-cursor-pointer stwc-items-center',
					disabled && 'stwc-cursor-not-allowed'
				)}
			>
				<input
					{...props}
					type="checkbox"
					ref={ref}
					disabled={disabled}
					className={cn(
						'stwc-peer stwc-h-4 stwc-w-4 stwc-cursor-pointer stwc-appearance-none stwc-rounded stwc-border stwc-border-gray-300 stwc-bg-white stwc-transition-all',
						'checked:stwc-border-[#586ADA] checked:stwc-bg-[#586ADA]',
						'focus:stwc-outline-none focus:stwc-ring-2 focus:stwc-ring-blue-200 focus:stwc-ring-offset-1',
						'disabled:stwc-cursor-not-allowed disabled:stwc-opacity-50',
						'after:stwc-absolute after:stwc-left-0 after:stwc-top-[2px] after:stwc-h-3 after:stwc-w-4',
						'after:stwc-bg-[url("data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTIiIGhlaWdodD0iMTAiIHZpZXdCb3g9IjAgMCAxMiAxMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cGF0aCBkPSJNMTAgMEw0IDZMLjUgMi41TC0uNzUgMy43NWw0IDRMNC4yNSA4bDctN3oiIGZpbGw9IiNGRkYiIGZpbGwtcnVsZT0iZXZlbm9kZCIvPjwvc3ZnPg==")] after:stwc-bg-[length:12px_10px] after:stwc-bg-center after:stwc-bg-no-repeat after:stwc-opacity-0',
						'checked:after:stwc-opacity-100',
						className
					)}
				/>
				{label && (
					<span
						className={cn(
							'stwc-ml-2 stwc-select-none stwc-text-sm stwc-text-gray-700',
							disabled && 'stwc-text-gray-400'
						)}
					>
						{label}
					</span>
				)}
			</label>
		);
	}
);

CheckBox.displayName = 'CheckBox';
