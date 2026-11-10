// Defense in depth; the offline gate also runs in Docker's internal-only network.
globalThis.fetch = async (input) => {
    const url = String(input instanceof Request ? input.url : input);
    throw new Error(`RELEASE_OFFLINE_FETCH_BLOCKED ${url}`);
};
