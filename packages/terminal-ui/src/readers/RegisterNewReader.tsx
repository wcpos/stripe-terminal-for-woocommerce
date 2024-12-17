import React from 'react';
import { Button } from '../components/Button/Button';
import { Group } from '../components/Group/Group';
import { Section } from '../components/Section/Section';
import { Text } from '../components/Text/Text';
import { TextInput } from '../components/TextInput/TextInput';
import Select from '../components/Select/Select';
import { Link } from '../components/Link/Link';

interface Location {
	id: string;
	display_name: string;
}

interface RegisterNewReaderProps {
	// listLocations: () => Promise<Location[]>;
	// onSubmitRegister: (readerLabel: string, readerCode: string, readerLocationId: string) => void;
	onClickCancel: () => void;
}

export const RegisterNewReader: React.FC<RegisterNewReaderProps> = ({
	// listLocations,
	// onSubmitRegister,
	onClickCancel,
}) => {
	const [locations, setLocations] = React.useState<Location[]>([]);
	const [readerCode, setReaderCode] = React.useState<string | null>(null);
	const [readerLabel, setReaderLabel] = React.useState<string | null>(null);
	const [readerLocationId, setReaderLocationId] = React.useState<string | null>(null);

	// React.useEffect(() => {
	// 	listLocations().then((fetchedLocations) => {
	// 		setLocations(fetchedLocations);
	// 		if (fetchedLocations.length >= 1) {
	// 			setReaderLocationId(fetchedLocations[0].id);
	// 		}
	// 	});
	// }, [listLocations]);

	// const handleSubmit = (event: React.FormEvent) => {
	// 	event.preventDefault();
	// 	if (readerCode && readerLabel && readerLocationId) {
	// 		onSubmitRegister(readerLabel, readerCode, readerLocationId);
	// 	}
	// };

	return (
		<Section>
			<form //onSubmit={handleSubmit}
			>
				<Group direction="column" spacing={16}>
					<Group direction="column" spacing={8}>
						<Text size={16} color="dark">
							Register new reader
						</Text>
						<Text size={12} color="lightGrey">
							Enter the key sequence 0-7-1-3-9 on the reader to display its unique registration
							code.
						</Text>
					</Group>

					<Group direction="column" spacing={8}>
						<Text size={14} color="darkGrey">
							Registration code
						</Text>
						<TextInput
							placeholder="quick-brown-fox"
							value={readerCode || ''}
							onChange={(str) => setReaderCode(str)}
							ariaLabel="Registration code"
						/>

						<Text size={14} color="darkGrey">
							Reader label
						</Text>
						<TextInput
							placeholder="Front desk"
							value={readerLabel || ''}
							onChange={(str) => setReaderLabel(str)}
							ariaLabel="Reader label"
						/>

						<Text size={14} color="darkGrey">
							Reader location
						</Text>
						{locations.length === 0 ? (
							<Text size={12} color="lightGrey">
								Looks like you don't have any locations yet. Start by creating one in{' '}
								<Link
									size={12}
									href="https://dashboard.stripe.com/terminal/locations"
									text="the dashboard"
								/>
								.
							</Text>
						) : (
							<Group direction="column" spacing={1}>
								<Select
									items={locations.map((location) => ({
										value: location.id,
										label: `${location.display_name} (${location.id})`,
									}))}
									value={readerLocationId || ''}
									onChange={(str) => setReaderLocationId(str)}
									ariaLabel="Reader location"
									required
								/>
								<Text size={10} color="lightGrey">
									You can create more Locations in{' '}
									<Link
										size={10}
										href="https://dashboard.stripe.com/terminal/locations"
										text="the dashboard"
									/>
									.
								</Text>
							</Group>
						)}
					</Group>

					<Group direction="row" alignment={{ justifyContent: 'flex-end' }}>
						<Button color="white" onClick={onClickCancel}>
							<Text color="darkGrey" size={14}>
								Cancel
							</Text>
						</Button>
						<Button type="submit" disabled={!readerCode || !readerLabel} color="primary">
							<Text color="white" size={14}>
								Register
							</Text>
						</Button>
					</Group>
				</Group>
			</form>
		</Section>
	);
};
