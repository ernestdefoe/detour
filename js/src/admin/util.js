import app from 'flarum/admin/app';

export const t = (key, params) => app.translator.trans(`ernestdefoe-detour.admin.${key}`, params);

export const api = (path) => `${app.forum.attribute('apiUrl')}/detour${path}`;

export const request = (method, path, body, params) => app.request({ method, url: api(path), body, params });

/** Shows an address as people typed it: decoded where it is valid. */
export function decode(url) {
  try {
    return decodeURIComponent(url);
  } catch (e) {
    return url;
  }
}
