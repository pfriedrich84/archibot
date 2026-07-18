import Empty from './Empty.svelte';
import InertiaForm from './InertiaForm.svelte';

export const Form = InertiaForm;
export const page: {
    props: Record<string, unknown> & {
        flash: Record<string, unknown>;
        auth: { user: { is_admin: boolean } };
    };
    url: string;
} = {
    props: { flash: {}, auth: { user: { is_admin: true } } },
    url: '/',
};
export const router = { reload: () => undefined, visit: () => undefined };
export const Head = Empty;
export const Link = Empty;
