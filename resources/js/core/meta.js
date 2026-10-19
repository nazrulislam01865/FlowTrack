export const metaContent = (name, fallback = '') =>
    document.querySelector(`meta[name="${name}"]`)?.content || fallback;
