import React, { ChangeEvent } from 'react';

import { cn } from '../lib/utils';

interface TextInputProps {
	placeholder?: string;
	value?: string;
	onChange: (value: string) => void;
	ariaLabel?: string;
	maxlength?: number;
	type?: string;
	min?: string;
	step?: string;
}

export const TextInput = ({
	placeholder = '',
	value = '',
	onChange,
	ariaLabel = '',
	maxlength,
	type = 'text',
	min,
	step,
	className,
}: TextInputProps) => {
	const handleChange = (e: ChangeEvent<HTMLInputElement>) => {
		onChange(e.target.value);
	};

	return (
		<input
			placeholder={placeholder}
			value={value}
			onChange={handleChange}
			className={cn(
				'stwc-bg-gray-100 stwc-rounded-md stwc-p-2 stwc-text-sm stwc-font-normal stwc-border-0 stwc-outline-none',
				'focus:stwc-ring-2 focus:stwc-ring-blue-400 focus:stwc-ring-opacity-50 stwc-transition-all placeholder:stwc-text-gray-400',
				className
			)}
			aria-label={ariaLabel}
			maxLength={maxlength}
			type={type}
			min={min}
			step={step}
		/>
	);
};
