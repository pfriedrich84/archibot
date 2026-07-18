type Route = { url: string; method: string };
type RouteFn = ((...args: unknown[]) => Route) & {
    form: (...args: unknown[]) => { action: string; method: string };
};
const make = (name: string): RouteFn => {
    const fn = ((...args: unknown[]): Route => ({
        url: `/test/${name}${args.length ? `?args=${encodeURIComponent(JSON.stringify(args))}` : ''}`,
        method: 'get',
    })) as RouteFn;
    fn.form = (...args: unknown[]) => ({
        action: fn(...args).url,
        method: 'post',
    });

    return fn;
};

export const index = make('index');
export const edit = make('edit');
export const update = make('update');
export const dashboard = make('dashboard');
export const home = make('home');
export const show = make('show');
export const create = make('create');
export const store = make('store');
export const destroy = make('destroy');
export const accept = make('accept');
export const reject = make('reject');
export const logout = make('logout');
