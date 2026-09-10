import { parsePhoneNumberFromString } from 'libphonenumber-js';

export function formatPhoneDisplay(telefono) {
    if (!telefono) return telefono;
    const parsed = parsePhoneNumberFromString(telefono);
    return parsed ? parsed.formatInternational() : telefono;
}
