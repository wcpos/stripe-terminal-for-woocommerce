import { type HTMLAttributes } from 'react';
import { cn } from '../lib/utils';
import { cva, type VariantProps } from 'class-variance-authority';

const textVariants = cva(
	[
		'stwc-font-medium stwc-leading-4 stwc-tracking-[-0.15px]',
		'stwc-font-[-apple-system,BlinkMacSystemFont,Segoe_UI,Roboto,Helvetica_Neue,Ubuntu]',
		'stwc-antialiased',
	],
	{
		variants: {
			color: {
				default: 'stwc-text-[#1A1F36]',
				muted: 'stwc-text-[#697386]',
				error: 'stwc-text-[#CD3D64]',
				lightGrey: 'stwc-text-[#8792a2]',
				grey: 'stwc-text-[#C1C9D2]',
				link: 'stwc-text-blue-400 hover:stwc-underline',
			},
		},
		defaultVariants: {
			color: 'default',
		},
	}
);

interface TextProps
	extends Omit<HTMLAttributes<HTMLParagraphElement>, 'color'>,
		VariantProps<typeof textVariants> {}

export const Text = ({ color, className, ...props }: TextProps) => {
	return <p className={cn(textVariants({ color }), className)} {...props} />;
};
