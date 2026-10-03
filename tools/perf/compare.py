import sys,re,statistics as st,collections
out,a,b=sys.argv[1:4]
def load(label):
    d=collections.defaultdict(list);
    for fn in ('view.txt','micro.txt','save.txt','client.txt'):
        try: lines=open(f"{out}/{label}/{fn}").read().splitlines()
        except FileNotFoundError: continue
        for l in lines:
            if '\t' not in l: continue
            k,v=l.split('\t');
            k=re.sub(r'^run\d+_','save_',k)       # aggregate runs
            try: d[k].append(float(v))
            except ValueError: pass
    return {k:st.median(v) for k,v in d.items()}
A,B=load(a),load(b)
print(f"{'metric':44}{a:>14}{b:>14}{'delta':>10}")
for k in sorted(set(A)|set(B)):
    x,y=A.get(k),B.get(k)
    dl='' if x in (None,0) or y is None else f"{(y-x)/x*100:+.0f}%"
    print(f"{k:44}{'' if x is None else format(x,'.2f'):>14}{'' if y is None else format(y,'.2f'):>14}{dl:>10}")
