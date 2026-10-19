@props(['users'=>collect()])
<div class="ft-finance-modal-backdrop" wire:key="collection-update-modal" wire:click.self="closeCollectionUpdate">
    <section class="ft-finance-modal ft-finance-small-modal" data-ft-feedback-scope="form" role="dialog" aria-modal="true" aria-labelledby="collectionUpdateTitle">
        <header class="ft-finance-modal-head"><div><h2 id="collectionUpdateTitle">Add collection update</h2><p>Record the latest follow-up and schedule the next collection action.</p></div><button type="button" wire:click="closeCollectionUpdate" aria-label="Close">×</button></header>
        @error('collectionForm')<div class="ft-finance-form-alert">{{ $message }}</div>@enderror
        <div class="ft-finance-small-grid">
            <label class="wide"><span>Collection owner</span><select wire:model="collectionOwnerId"><option value="">Unassigned</option>@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select></label>
            <label><span>Follow-up date <b>*</b></span><input type="date" wire:model="collectionFollowUpDate">@error('collectionFollowUpDate')<small class="error">{{ $message }}</small>@enderror</label>
            <label><span>Next follow-up</span><input type="date" wire:model="collectionNextFollowUpDate">@error('collectionNextFollowUpDate')<small class="error">{{ $message }}</small>@enderror</label>
            <label class="wide"><span>Latest note <b>*</b></span><textarea wire:model="collectionNote" placeholder="What did the client say? What happens next?"></textarea>@error('collectionNote')<small class="error">{{ $message }}</small>@enderror</label>
        </div>
        <footer class="ft-finance-modal-foot"><span></span><div><button type="button" class="secondary" wire:click="closeCollectionUpdate">Cancel</button><button type="button" class="primary" wire:click="saveCollectionUpdate" wire:loading.attr="disabled" wire:target="saveCollectionUpdate">Add update</button></div></footer>
    </section>
</div>
