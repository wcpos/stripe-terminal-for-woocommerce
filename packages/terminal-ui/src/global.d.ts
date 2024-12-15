import type { StripeTerminal } from '@stripe/terminal-js';

declare global {
	interface Window {
		StripeTerminal: StripeTerminal;
		stwcConfig: {
			restUrl: string;
		};
	}
}
