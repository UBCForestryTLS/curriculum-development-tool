export async function downloadReport(endpoint, payload, filename) {
    const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json', 'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify(payload),
    });
    if (!response.ok) {
        if ([401, 419].includes(response.status)) throw new Error('Your session has expired. Refresh the page and sign in again.');
        if (response.status === 403) throw new Error('You no longer have permission to export this program.');
        if (response.status === 422) throw new Error('These report settings are no longer valid. Refresh the page and select your report settings again.');
        throw new Error('The report could not be downloaded. Please try again.');
    }
    const expectedType = payload.format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    if (!response.headers.get('Content-Type')?.includes(expectedType)) {
        throw new Error('The report could not be downloaded. Refresh the page and try again.');
    }
    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement('a');
    link.href = url;
    link.download = response.headers.get('Content-Disposition')?.match(/filename="?([^";]+)"?/i)?.[1]
        ?? `${filename}.${payload.format}`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 60000);
}
